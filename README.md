# Octopus LLM PHP Gateway
![Packagist Version](https://img.shields.io/packagist/v/octopus-llm/php)
![PHP Version](https://img.shields.io/packagist/php-v/octopus-llm/php)
![License](https://img.shields.io/packagist/l/octopus-llm/php)
![Downloads](https://img.shields.io/packagist/dt/octopus-llm/php)

OpenAI-compatible AI gateway with multi-key rotation, circuit breaker, and zero-cost free tier management for production usage.

## Overview
Octopus-LLM is a robust PHP gateway that acts as a wrapper around the `openai-php/client` library. It automatically handles API key rotation using least-recently-used (LRU) patterns across multiple AI providers (like Groq, Cerebras, and OpenRouter), falls back to secondary providers if primary keys fail, protects your application using a configurable circuit breaker, and exposes events for recovery monitoring – all without needing a complicated infrastructure database.

## Zero-Cost Free Tier Strategy

Octopus-LLM is designed to maximize free tier API quotas across providers.
With enough keys, you can run production AI workloads at zero cost:

| Provider    | Free Limit       | Keys needed for ~1k req/day |
|-------------|------------------|-----------------------------|
| Groq        | 14,400 req/day   | 1                           |
| Cerebras    | ~14,400 req/day  | 1                           |
| OpenRouter  | Varies by model  | 1–3                         |

Register multiple free accounts → add all keys to the pool →
Octopus-LLM handles rotation automatically.


## Installation
You can install this package easily via Composer:
```bash
composer require octopus-llm/php
```

## Quick Start
```php
require 'vendor/autoload.php';

use OctopusLLM\Gateway\OctopusLLM;

// Initialize the Gateway config
$llm = new OctopusLLM([
    'providers' => [
        [
            'id' => 'groq',
            'baseURL' => 'https://api.groq.com/openai/v1',
            'model' => 'llama3-70b-8192',
            'priority' => 1,
            'keys' => ['gsk_foo1', 'gsk_foo2'],
            'cooldown' => 60
        ],
        [
            'id' => 'openrouter',
            'baseURL' => 'https://openrouter.ai/api/v1',
            'model' => 'meta-llama/llama-3-70b-instruct',
            'priority' => 2,
            'keys' => ['sk-or-foo1'],
            'cooldown' => 120
        ]
    ]

]);

// Use it like a regular OpenAI Client
try {
    $response = $llm->chat([
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user', 'content' => 'Explain PHP decorators.']
    ]);

    echo $response->content;
    echo "Served by: " . $response->provider;
    
} catch (\OctopusLLM\Gateway\Exceptions\GatewayExhaustedException $e) {
    echo "All providers and keys failed!";
}
```

## Configuration
The `OctopusLLM` constructor requires an array configuration.

```php
[
    // Array of AI Providers
    'providers' => [
        [
            'id' => 'provider1',            // string: Unique identifier
            'baseURL' => 'https://...',     // string: Provider base API URL
            'model' => 'model-name',        // string: Targeted internal model
            'priority' => 1,                // int: Lower number = higher priority
            'keys' => ['key1', 'key2'],     // array: API keys for this provider
            'extraHeaders' => [             // array: Extra HTTP headers (optional)
                'HTTP-Referer' => '...'
            ],
            'cooldown' => 60                // int: Circuit breaker recovery timeout (optional, default: 60)
        ]
    ],
    
    // Gateway security guards (optional)
    'guard' => [
        'timeoutSeconds' => 10,     // Network request timeout (default: 10)
        'maxInputTokens' => 4000,   // Guard max input tokens (approximated)
        'maxRetries' => 2,          // Retries per key on 5xx errors (default: 2)
        'maxOutputTokens' => 1000   // Global max generation tokens (default: 1000)
    ],
    
    // Recovery script configurations (optional)
    'recovery' => [
        'pingTimeout' => 5         // Timeout for `runRecovery()` pings in seconds
    ],
    
    // Failure thresholds (optional)
    'circuitBreaker' => [
        'failureThreshold' => 3    // Number of failures before marking key as inactive
    ],
    
    // Custom state storage (optional, default: JsonFileStorage)
    'storage' => new CustomStorageImpl() 
]
```

## API Reference

### `chat(array $messages, array $options = []): ChatResponse`
Sends a chat request using the gateway configurations. Supports standard array options for overriding defaults (`stream`, `maxTokens`, `temperature`, `forceProvider`).

### `getStatus(): StatusResponse`
Returns a unified DTO of all available providers, detailing active keys, inactive keys, and total stats.

### `ping(string $providerId, int $keyIndex): bool`
Checks if a specific inactive key logic has recovered by pinging the configured `baseURL`/`models` endpoint. Returns boolean success.

### `runRecovery(): RecoveryReport`
A utility method to scan all inactive keys that passed the cooldown timeframe. Automatically checks if they are recovered and re-activates them if successful.

### `on(string $event, callable $callback): self`
Attach event listeners such as when fallback happens or a key goes bad.

## Streaming
You can enable native streaming via the options array, passing the `onChunk` callback:

```php
$llm->chat($messages, [
    'stream' => true,
    'onChunk' => function(string $chunk) {
        echo $chunk; // Directly stream into stdout
        flush();
    }
]);
```

## Error Handling
Octopus-LLM uses explicit granular exceptions under `OctopusLLM\Gateway\Exceptions\*` namespace:
- **`InputTooLongException`**: Sent payload exceeds configured `maxInputTokens`.
- **`GatewayExhaustedException`**: Out of all active operational keys and Fallbacks.
- **`RateLimitException`**: Handled natively by circuit breaker, but internally emitted.
- **`AuthenticationException`**: Handled natively when key goes rogue.
- **`CircuitOpenException`**: Prevented access due to open circuit limits.
- **`ProviderException`**: Base exception layer interface.

## Events
You can hook into real-time health events using `$llm->on(string $event, callable $callback)`:

- **`key.deactivated`**: `($providerId, $keyIndex, $reason)` -> Used for tracking broken API keys.
- **`key.recovered`**: `($providerId, $keyIndex)` -> Fires when a key successfully leaves the penalized box.
- **`provider.exhausted`**: `($providerId)` -> Triggered when an entire provider array has failed.
- **`fallback`**: `($failedProviderId, $nextProviderId)` -> When attempting automatic fallback to next priority.

## CI4 Integration
If you intend to use this package with CodeIgniter 4, follow these steps:

1. **Register the Service**: Add an `octopus()` method to `app/Config/Services.php`:
```php
public static function octopus(bool $getShared = true): \OctopusLLM\Gateway\OctopusLLM
{
    if ($getShared) {
        return static::getSharedInstance('octopus');
    }

    return new \OctopusLLM\Gateway\OctopusLLM([
        'providers' => [
            [
                'id'       => 'groq',
                'baseURL'  => 'https://api.groq.com/openai/v1',
                'model'    => 'llama-3.1-8b-instant',
                'keys'     => explode(',', env('GROQ_KEYS', '')),
                'priority' => 1,
                'cooldown' => 60,
            ],
        ],
    ]);
}
```

2. **Register the Recovery Command**: CI4 does not auto-discover commands from vendor packages. Create a wrapper at `app/Commands/OctopusRecover.php`:
```php
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class OctopusRecover extends BaseCommand
{
    protected $group       = 'Octopus';
    protected $name        = 'octopus:recover';
    protected $description = 'Ping all inactive keys and reactivate if healthy.';

    public function run(array $params)
    {
        $octopus = \Config\Services::octopus();
        $report  = $octopus->runRecovery();

        CLI::write('Recovery complete.', 'green');
        CLI::write('Total pinged : ' . $report->total);
        CLI::write('Recovered    : ' . count($report->recovered));
        CLI::write('Still failed : ' . count($report->failed));
    }
}
```

3. **Schedule Recovery**: Run via CI4 Tasks or crontab every minute:
```bash
* * * * * cd /path/to/your/app && php spark octopus:recover >> /dev/null 2>&1
```

4. **Usage**:
```php
$response = \Config\Services::octopus()->chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user',   'content' => $userMessage],
]);
echo $response->content;
```

## Custom Storage
OctopusLLM exposes `OctopusLLM\Gateway\Contracts\StorageInterface` which requires two methods:
`load(): array` and `save(array $state): void`. 
Implement your own persistent storage logic (e.g. Redis, MySQL) for horizontal web-scaling deployments.

## Non-Goals
This version 1.x library **explicitly does NOT aim to support**:
- Audio/Vision multi-modal data processing.
- Persistent complex conversation thread management (Send full context history array manually).
- Custom non-chat completion endpoints (such as `embeddings/` or `moderation/`).
- Gemini native API support (use Gemini via OpenRouter instead).

