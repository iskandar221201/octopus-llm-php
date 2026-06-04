<?php

namespace OctopusLLM\Gateway;

use OctopusLLM\Gateway\Contracts\StorageInterface;
use OctopusLLM\Gateway\Storage\JsonFileStorage;
use OctopusLLM\Gateway\Exceptions\AuthenticationException;
use OctopusLLM\Gateway\Exceptions\CircuitOpenException;
use OctopusLLM\Gateway\Exceptions\GatewayExhaustedException;
use OctopusLLM\Gateway\Exceptions\InputTooLongException;
use OctopusLLM\Gateway\Exceptions\ProviderException;
use OctopusLLM\Gateway\Exceptions\RateLimitException;
use OctopusLLM\Gateway\Exceptions\TimeoutException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use OpenAI;

class OctopusLLM
{
    /**
     * @var array<int, array<string, mixed>> Providers sorted by priority ascending
     */
    private array $providers;

    /**
     * @var array{timeoutSeconds: int, maxInputTokens: int, maxRetries: int, maxOutputTokens: int}
     */
    private array $guard;

    /**
     * @var array{pingTimeout: int}
     */
    private array $recovery;

    /**
     * @var array{failureThreshold: int}
     */
    private array $circuitBreaker;

    private StorageInterface $storage;

    /**
     * @var array<string, callable[]>
     */
    private array $listeners = [];

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config)
    {
        // Validate providers exist and not empty
        if (empty($config['providers'])) {
            throw new \InvalidArgumentException('Config "providers" is required and must not be empty.');
        }

        // Validate each provider has required fields and non-empty keys
        foreach ($config['providers'] as $index => $provider) {
            foreach (['id', 'baseURL', 'model', 'keys', 'priority'] as $field) {
                if (!isset($provider[$field])) {
                    throw new \InvalidArgumentException("Provider at index {$index} is missing required field \"{$field}\".");
                }
            }

            if (empty($provider['keys'])) {
                throw new \InvalidArgumentException("Provider \"{$provider['id']}\" must have at least one key.");
            }
        }

        // Set defaults for guard
        $this->guard = array_merge([
            'timeoutSeconds' => 10,
            'maxInputTokens' => 4000,
            'maxRetries' => 2,
            'maxOutputTokens' => 1000,
        ], $config['guard'] ?? []);

        // Set defaults for recovery
        $this->recovery = array_merge([
            'pingTimeout' => 5,
        ], $config['recovery'] ?? []);

        // Set defaults for circuitBreaker
        $this->circuitBreaker = array_merge([
            'failureThreshold' => 3,
        ], $config['circuitBreaker'] ?? []);

        // Sort providers by priority ascending (lower number = higher priority)
        $providers = $config['providers'];
        usort($providers, fn($a, $b) => $a['priority'] <=> $b['priority']);
        $this->providers = $providers;

        // Instantiate storage
        if (isset($config['storage']) && $config['storage'] instanceof StorageInterface) {
            $this->storage = $config['storage'];
        } else {
            $this->storage = new JsonFileStorage();
        }
    }

    /**
     * Send a chat request with LRU key rotation, provider fallback, circuit breaker, and retry logic.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     *
     * @return ChatResponse
     *
     * @throws InputTooLongException
     * @throws GatewayExhaustedException
     */
    public function chat(array $messages, array $options = []): ChatResponse
    {
        // Merge options defaults
        $options = array_merge([
            'stream' => false,
            'maxTokens' => $this->guard['maxOutputTokens'],
            'temperature' => 0.7,
            'forceProvider' => null,
            'onChunk' => null,
        ], $options);

        // Pre-flight guard — maxInputTokens
        $totalChars = 0;
        foreach ($messages as $message) {
            $totalChars += mb_strlen($message['content'] ?? '');
        }
        $estimated = (int) ceil($totalChars / 4);

        if ($estimated > $this->guard['maxInputTokens']) {
            throw new InputTooLongException($estimated, $this->guard['maxInputTokens']);
        }

        // Load state from storage
        $state = $this->storage->load();

        // Initialize state per-provider/key if not present
        $this->initializeState($state);

        // Track attempts
        $attempts = 0;
        $startTime = microtime(true);

        // Filter providers if forceProvider is set
        $providers = $this->providers;
        if ($options['forceProvider'] !== null) {
            $providers = array_filter($providers, fn($p) => $p['id'] === $options['forceProvider']);
            $providers = array_values($providers);
        }

        // Iterate providers by priority
        foreach ($providers as $providerIndex => $provider) {
            $providerId = $provider['id'];

            // Get keys for this provider, sort by lastUsed ascending (LRU)
            $keys = $state[$providerId]['keys'] ?? [];
            usort($keys, function ($a, $b) {
                // null lastUsed = highest priority (never used)
                if ($a['lastUsed'] === null && $b['lastUsed'] === null) {
                    return 0;
                }
                if ($a['lastUsed'] === null) {
                    return -1;
                }
                if ($b['lastUsed'] === null) {
                    return 1;
                }
                return strcmp($a['lastUsed'], $b['lastUsed']);
            });

            // Iterate keys
            foreach ($keys as $keyData) {
                $keyIndex = $keyData['index'];
                $keyStatus = $keyData['status'];

                // Skip if key inactive and cooldown not elapsed
                if ($keyStatus === 'inactive') {
                    $markedAt = $keyData['markedAt'] ?? null;
                    if ($markedAt !== null) {
                        // Cooldown: per-provider config, default 60s
                        $cooldownSeconds = $provider['cooldown'] ?? 60;
                        $elapsed = time() - strtotime($markedAt);
                        if ($elapsed < $cooldownSeconds) {
                            continue; // cooldown not elapsed, skip
                        }
                        // Cooldown elapsed — treat as half-open (can be tried)
                    } else {
                        continue; // inactive with no markedAt, skip
                    }
                }

                // Create openai-php/client instance
                $factory = OpenAI::factory()
                    ->withApiKey($provider['keys'][$keyIndex])
                    ->withBaseUri($provider['baseURL'])
                    ->withHttpHeader('Content-Type', 'application/json');

                // Add extraHeaders if present
                if (isset($provider['extraHeaders'])) {
                    foreach ($provider['extraHeaders'] as $name => $value) {
                        $factory = $factory->withHttpHeader($name, $value);
                    }
                }

                // Set timeout
                $factory = $factory->withHttpClient(
                    new GuzzleClient(['timeout' => $this->guard['timeoutSeconds']])
                );

                $client = $factory->make();

                // Retry loop (up to maxRetries + 1 attempts)
                $maxRetries = $this->guard['maxRetries'];
                for ($retry = 0; $retry <= $maxRetries; $retry++) {
                    $attempts++;

                    try {
                        if ($options['stream'] === false) {
                            // Non-streaming request
                            $result = $client->chat()->create([
                                'model' => $provider['model'],
                                'messages' => $messages,
                                'max_tokens' => $options['maxTokens'],
                                'temperature' => $options['temperature'],
                            ]);
                            $content = $result->choices[0]->message->content;
                        } else {
                            // Streaming request
                            $stream = $client->chat()->createStreamed([
                                'model' => $provider['model'],
                                'messages' => $messages,
                                'max_tokens' => $options['maxTokens'],
                                'temperature' => $options['temperature'],
                            ]);
                            $content = '';
                            foreach ($stream as $response) {
                                $delta = $response->choices[0]->delta->content ?? '';
                                if ($delta !== '' && is_callable($options['onChunk'])) {
                                    ($options['onChunk'])($delta);
                                }
                                $content .= $delta;
                            }
                        }

                        // On success: update state
                        $stateKeyIndex = $this->findStateKeyIndex($state[$providerId]['keys'], $keyIndex);
                        $state[$providerId]['keys'][$stateKeyIndex]['lastUsed'] = date('c');

                        // Reset failure count if was half-open (inactive but cooldown elapsed)
                        if ($keyStatus === 'inactive') {
                            $state[$providerId]['keys'][$stateKeyIndex]['status'] = 'active';
                            $state[$providerId]['keys'][$stateKeyIndex]['failureCount'] = 0;
                            $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] = null;
                        }

                        // Save state
                        $this->storage->save($state);

                        // Calculate latency
                        $latencyMs = (microtime(true) - $startTime) * 1000;

                        $fallbackUsed = $providerIndex > 0;

                        return new ChatResponse(
                            $content,
                            $providerId,
                            $keyIndex,
                            $provider['model'],
                            $latencyMs,
                            $attempts,
                            $fallbackUsed
                        );
                    } catch (\OpenAI\Exceptions\ErrorException $e) {
                        $httpCode = $e->getCode();

                        $stateKeyIndex = $this->findStateKeyIndex($state[$providerId]['keys'], $keyIndex);

                        if ($httpCode === 429) {
                            // Rate limit — immediately mark key inactive
                            $state[$providerId]['keys'][$stateKeyIndex]['status'] = 'inactive';
                            $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] = date('c');
                            $this->emit('key.deactivated', $providerId, $keyIndex, 'rate_limit');
                            break; // Don't retry, next key
                        } elseif ($httpCode === 401 || $httpCode === 403) {
                            // Auth error — immediately mark key inactive
                            $state[$providerId]['keys'][$stateKeyIndex]['status'] = 'inactive';
                            $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] = date('c');
                            $this->emit('key.deactivated', $providerId, $keyIndex, 'auth_error');
                            break; // Don't retry, next key
                        } else {
                            // Other error (5xx, etc.)
                            if ($retry < $maxRetries) {
                                continue; // Retry
                            }

                            // Retries exhausted — increment failure count
                            $state[$providerId]['keys'][$stateKeyIndex]['failureCount']++;
                            $failureCount = $state[$providerId]['keys'][$stateKeyIndex]['failureCount'];

                            if ($failureCount >= $this->circuitBreaker['failureThreshold']) {
                                $state[$providerId]['keys'][$stateKeyIndex]['status'] = 'inactive';
                                $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] = date('c');
                                $this->emit('key.deactivated', $providerId, $keyIndex, 'failure_threshold');
                            }
                        }
                    } catch (GuzzleConnectException $e) {
                        // Timeout / connection error
                        if ($retry < $maxRetries) {
                            continue; // Retry
                        }

                        // Retries exhausted — increment failure count
                        $stateKeyIndex = $this->findStateKeyIndex($state[$providerId]['keys'], $keyIndex);
                        $state[$providerId]['keys'][$stateKeyIndex]['failureCount']++;
                        $failureCount = $state[$providerId]['keys'][$stateKeyIndex]['failureCount'];

                        if ($failureCount >= $this->circuitBreaker['failureThreshold']) {
                            $state[$providerId]['keys'][$stateKeyIndex]['status'] = 'inactive';
                            $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] = date('c');
                            $this->emit('key.deactivated', $providerId, $keyIndex, 'timeout');
                        }
                    }
                }
            }

            // All keys in this provider exhausted
            $this->emit('provider.exhausted', $providerId);

            // If there is a next provider, emit fallback event
            if ($providerIndex < count($providers) - 1) {
                $this->emit('fallback', $providerId, $providers[$providerIndex + 1]['id']);
            }
        }

        // All providers exhausted
        $this->storage->save($state);
        throw new GatewayExhaustedException();
    }

    /**
     * Get the current status of all providers and keys.
     *
     * @return StatusResponse
     */
    public function getStatus(): StatusResponse
    {
        $state = $this->storage->load();
        $this->initializeState($state);

        $providerStatuses = [];
        $totalActive = 0;
        $totalInactive = 0;

        foreach ($this->providers as $provider) {
            $providerId = $provider['id'];
            $keys = $state[$providerId]['keys'] ?? [];

            $keyStatuses = [];
            foreach ($keys as $keyData) {
                $keyStatuses[] = new KeyStatus(
                    $keyData['index'],
                    $keyData['status'],
                    $keyData['failureCount'],
                    $keyData['lastUsed'],
                    $keyData['markedAt']
                );

                if ($keyData['status'] === 'active') {
                    $totalActive++;
                } else {
                    $totalInactive++;
                }
            }

            $providerStatuses[] = new ProviderStatus(
                $providerId,
                $provider['priority'],
                $keyStatuses
            );
        }

        return new StatusResponse($providerStatuses, $totalActive, $totalInactive);
    }

    /**
     * Ping a specific key to check if it has recovered.
     *
     * @param string $providerId
     * @param int $keyIndex
     *
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    public function ping(string $providerId, int $keyIndex): bool
    {
        $state = $this->storage->load();
        $this->initializeState($state);

        // Find provider by id
        $provider = null;
        foreach ($this->providers as $p) {
            if ($p['id'] === $providerId) {
                $provider = $p;
                break;
            }
        }

        if ($provider === null) {
            throw new \InvalidArgumentException("Provider \"{$providerId}\" not found.");
        }

        // Find key by index
        if (!isset($provider['keys'][$keyIndex])) {
            throw new \InvalidArgumentException("Key index {$keyIndex} not found for provider \"{$providerId}\".");
        }

        // Check cooldown
        $stateKeyIndex = $this->findStateKeyIndex($state[$providerId]['keys'], $keyIndex);
        $markedAt = $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] ?? null;

        if ($markedAt !== null) {
            $cooldownSeconds = $provider['cooldown'] ?? 60;
            $elapsed = time() - strtotime($markedAt);
            if ($elapsed < $cooldownSeconds) {
                return false; // Too early to ping
            }
        }

        try {
            // Send GET /models request
            $client = OpenAI::factory()
                ->withApiKey($provider['keys'][$keyIndex])
                ->withBaseUri($provider['baseURL'])
                ->withHttpClient(new GuzzleClient(['timeout' => $this->recovery['pingTimeout']]))
                ->make();

            $client->models()->list();

            // On success: mark key active
            $state[$providerId]['keys'][$stateKeyIndex]['status'] = 'active';
            $state[$providerId]['keys'][$stateKeyIndex]['failureCount'] = 0;
            $state[$providerId]['keys'][$stateKeyIndex]['markedAt'] = null;

            $this->emit('key.recovered', $providerId, $keyIndex);
            $this->storage->save($state);

            return true;
        } catch (\Throwable $e) {
            // Keep inactive, save state
            $this->storage->save($state);
            return false;
        }
    }

    /**
     * Run recovery for all inactive keys whose cooldown has elapsed.
     *
     * @return RecoveryReport
     */
    public function runRecovery(): RecoveryReport
    {
        $recovered = [];
        $failed = [];

        foreach ($this->providers as $provider) {
            $providerId = $provider['id'];

            // Load fresh state per provider (ping() akan handle sisanya)
            $state = $this->storage->load();
            $this->initializeState($state);
            $keys = $state[$providerId]['keys'] ?? [];

            foreach ($keys as $keyData) {
                if ($keyData['status'] !== 'inactive') {
                    continue;
                }

                $markedAt = $keyData['markedAt'] ?? null;
                if ($markedAt === null) {
                    continue;
                }

                // Check if cooldown has elapsed
                $cooldownSeconds = $provider['cooldown'] ?? 60;
                $elapsed = time() - strtotime($markedAt);
                if ($elapsed < $cooldownSeconds) {
                    continue; // Cooldown not yet elapsed
                }

                // Eligible for recovery — ping this key
                $result = $this->ping($providerId, $keyData['index']);

                if ($result) {
                    $recovered[] = ['provider' => $providerId, 'keyIndex' => $keyData['index']];
                } else {
                    $failed[] = ['provider' => $providerId, 'keyIndex' => $keyData['index']];
                }
            }
        }

        return new RecoveryReport($recovered, $failed, count($recovered) + count($failed));
    }

    /**
     * Register an event listener.
     *
     * @param string $event
     * @param callable $callback
     *
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public function on(string $event, callable $callback): self
    {
        $validEvents = ['key.deactivated', 'key.recovered', 'provider.exhausted', 'fallback'];

        if (!in_array($event, $validEvents, true)) {
            throw new \InvalidArgumentException(
                "Unknown event \"{$event}\". Valid events: " . implode(', ', $validEvents)
            );
        }

        $this->listeners[$event][] = $callback;

        return $this;
    }

    /**
     * Emit an event to all registered listeners.
     * Listener errors do NOT interrupt main flow.
     *
     * @param string $event
     * @param mixed ...$args
     */
    private function emit(string $event, mixed ...$args): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener(...$args);
            } catch (\Throwable $e) {
                // Listener error must NOT interrupt main flow
            }
        }
    }

    /**
     * Initialize state for providers/keys that don't yet have state entries.
     *
     * @param array<string, mixed> &$state
     */
    private function initializeState(array &$state): void
    {
        foreach ($this->providers as $provider) {
            if (!isset($state[$provider['id']])) {
                $state[$provider['id']] = [
                    'keys' => array_map(fn($i) => [
                        'index' => $i,
                        'status' => 'active',
                        'failureCount' => 0,
                        'lastUsed' => null,
                        'markedAt' => null,
                    ], array_keys($provider['keys'])),
                ];
            }
        }
    }

    /**
     * Find the array index of a key in the state array by its logical key index.
     *
     * @param array<int, array<string, mixed>> $stateKeys
     * @param int $keyIndex
     *
     * @return int
     */
    private function findStateKeyIndex(array $stateKeys, int $keyIndex): int
    {
        foreach ($stateKeys as $i => $keyData) {
            if ($keyData['index'] === $keyIndex) {
                return $i;
            }
        }

        return $keyIndex;
    }
}
