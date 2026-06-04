<?php
namespace OctopusLLM\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OctopusLLM\Gateway\OctopusLLM;
use OctopusLLM\Gateway\Storage\NullStorage;
use OctopusLLM\Gateway\Storage\JsonFileStorage;
use OctopusLLM\Gateway\Exceptions\GatewayExhaustedException;

class RotationTest extends TestCase
{
    private function getConfig(array $overrides = []): array
    {
        return array_merge([
            'providers' => [
                [
                    'id' => 'provider1',
                    'priority' => 1,
                    'baseURL' => 'http://127.0.0.1:65535', // Dead port for fast failures
                    'model' => 'model-1',
                    'keys' => ['key-p1-1', 'key-p1-2'],
                ],
                [
                    'id' => 'provider2',
                    'priority' => 2,
                    'baseURL' => 'http://127.0.0.1:65535',
                    'model' => 'model-2',
                    'keys' => ['key-p2-1'],
                ]
            ],
            'guard' => [
                'timeoutSeconds' => 1,
                'maxRetries' => 0, // Fail fast
                'maxInputTokens' => 1000,
            ],
            'circuitBreaker' => [
                'failureThreshold' => 1, // Immediately deactivate on failure
            ],
        ], $overrides);
    }

    public function test_selects_least_recently_used_key()
    {
        // Mock state to force key-p1-2 to be older (LRU)
        $storage = new NullStorage();
        $mockState = [
            'provider1' => [
                'keys' => [
                    ['index' => 0, 'status' => 'active', 'lastUsed' => '2023-01-01T12:00:00+00:00', 'failureCount' => 0, 'markedAt' => null],
                    ['index' => 1, 'status' => 'active', 'lastUsed' => '2023-01-01T10:00:00+00:00', 'failureCount' => 0, 'markedAt' => null],
                ]
            ]
        ];

        $storageMock = $this->createMock(\OctopusLLM\Gateway\Contracts\StorageInterface::class);
        $storageMock->method('load')->willReturn($mockState);

        $config = $this->getConfig();
        $config['storage'] = $storageMock;
        $llm = new OctopusLLM($config);

        $selectedKeyIndex = null;
        $llm->on('key.deactivated', function ($providerId, $keyIndex, $reason) use (&$selectedKeyIndex) {
            if ($selectedKeyIndex === null) {
                $selectedKeyIndex = $keyIndex; // Capture the first key that failed (since port is dead)
            }
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'hello']]);
        } catch (GatewayExhaustedException $e) {
        }

        // key-p1-2 (index 1) has older lastUsed, should be selected first
        $this->assertEquals(1, $selectedKeyIndex);
    }

    public function test_selects_null_last_used_first()
    {
        $mockState = [
            'provider1' => [
                'keys' => [
                    ['index' => 0, 'status' => 'active', 'lastUsed' => '2023-01-01T12:00:00+00:00', 'failureCount' => 0, 'markedAt' => null],
                    ['index' => 1, 'status' => 'active', 'lastUsed' => null, 'failureCount' => 0, 'markedAt' => null], // Should be prioritized
                ]
            ]
        ];

        $storageMock = $this->createMock(\OctopusLLM\Gateway\Contracts\StorageInterface::class);
        $storageMock->method('load')->willReturn($mockState);

        $config = $this->getConfig();
        $config['storage'] = $storageMock;
        $llm = new OctopusLLM($config);

        $selectedKeyIndex = null;
        $llm->on('key.deactivated', function ($providerId, $keyIndex, $reason) use (&$selectedKeyIndex) {
            if ($selectedKeyIndex === null) {
                $selectedKeyIndex = $keyIndex;
            }
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'hello']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertEquals(1, $selectedKeyIndex);
    }

    public function test_skips_inactive_keys()
    {
        $mockState = [
            'provider1' => [
                'keys' => [
                    ['index' => 0, 'status' => 'inactive', 'lastUsed' => null, 'failureCount' => 3, 'markedAt' => date('c')], // Active cooldown
                    ['index' => 1, 'status' => 'active', 'lastUsed' => date('c'), 'failureCount' => 0, 'markedAt' => null],
                ]
            ]
        ];

        $storageMock = $this->createMock(\OctopusLLM\Gateway\Contracts\StorageInterface::class);
        $storageMock->method('load')->willReturn($mockState);

        $config = $this->getConfig();
        $config['storage'] = $storageMock;
        $llm = new OctopusLLM($config);

        $failedKeys = [];
        $llm->on('key.deactivated', function ($providerId, $keyIndex, $reason) use (&$failedKeys) {
            $failedKeys[] = $keyIndex;
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'hello']]);
        } catch (GatewayExhaustedException $e) {
        }

        // Key 0 is inactive and within cooldown, so it should be skipped entirely. Only Key 1 fails.
        $this->assertContains(1, $failedKeys);
        $this->assertNotContains(0, $failedKeys);
    }

    public function test_falls_back_to_next_provider()
    {
        $storage = new NullStorage(); // Empty state is fine
        $config = $this->getConfig();
        $config['storage'] = $storage;
        $llm = new OctopusLLM($config);

        $fallbackCalled = false;
        $llm->on('fallback', function ($failedProviderId, $nextProviderId) use (&$fallbackCalled) {
            if ($failedProviderId === 'provider1' && $nextProviderId === 'provider2') {
                $fallbackCalled = true;
            }
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertTrue($fallbackCalled, 'Fallback event to provider2 should be emitted.');
    }

    public function test_respects_provider_priority_order()
    {
        $config = $this->getConfig();
        // Reverse order in config but priority dictates provider2 (pri 1) goes before provider1 (pri 2)
        $config['providers'] = [
            [
                'id' => 'provider-lower',
                'priority' => 10,
                'baseURL' => 'http://127.0.0.1:65535',
                'model' => 'model',
                'keys' => ['k1'],
            ],
            [
                'id' => 'provider-higher',
                'priority' => 1,
                'baseURL' => 'http://127.0.0.1:65535',
                'model' => 'model',
                'keys' => ['k2'],
            ]
        ];
        $config['storage'] = new NullStorage();

        $llm = new OctopusLLM($config);

        $triedProviders = [];
        $llm->on('provider.exhausted', function ($providerId) use (&$triedProviders) {
            $triedProviders[] = $providerId;
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 't']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertEquals(['provider-higher', 'provider-lower'], $triedProviders);
    }
}
