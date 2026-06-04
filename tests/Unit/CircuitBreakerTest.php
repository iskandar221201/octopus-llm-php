<?php
namespace OctopusLLM\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OctopusLLM\Gateway\OctopusLLM;
use OctopusLLM\Gateway\Storage\NullStorage;
use OctopusLLM\Gateway\Exceptions\GatewayExhaustedException;
use GuzzleHttp\Client as MockGuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset mock states
        MockGuzzleClient::$mockResponses = [];
        MockGuzzleClient::$mockException = null;
    }

    private function getConfig(array $overrides = []): array
    {
        return array_merge([
            'providers' => [
                [
                    'id' => 'provider1',
                    'priority' => 1,
                    'baseURL' => 'http://dummy.local',
                    'model' => 'model-1',
                    'keys' => ['key-1'],
                    'cooldown' => 2, // 2 seconds cooldown
                ]
            ],
            'guard' => [
                'timeoutSeconds' => 1,
                'maxRetries' => 0,
            ],
            'circuitBreaker' => [
                'failureThreshold' => 2,
            ],
            'storage' => new NullStorage()
        ], $overrides);
    }

    public function test_marks_key_inactive_after_failure_threshold()
    {
        $llm = new OctopusLLM($this->getConfig(['circuitBreaker' => ['failureThreshold' => 2]]));

        $deactivated = false;
        $llm->on('key.deactivated', function () use (&$deactivated) {
            $deactivated = true;
        });

        // 1st attempt -> failure count = 1
        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }
        $this->assertFalse($deactivated);

        // 2nd attempt -> failure count = 2 -> marks inactive
        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertTrue($deactivated);

        // Assert status
        $status = $llm->getStatus();
        $this->assertEquals('inactive', $status->providers[0]->keys[0]->status);
    }

    public function test_does_not_mark_inactive_below_threshold()
    {
        $llm = new OctopusLLM($this->getConfig(['circuitBreaker' => ['failureThreshold' => 3]]));

        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        $status = $llm->getStatus();
        $this->assertEquals('active', $status->providers[0]->keys[0]->status);
        $this->assertEquals(2, $status->providers[0]->keys[0]->failureCount);
    }

    public function test_rate_limit_immediately_marks_inactive()
    {
        $llm = new OctopusLLM($this->getConfig(['circuitBreaker' => ['failureThreshold' => 5]])); // High threshold

        // 429 response - should instantly deactivate regardless of threshold
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(429, ['error' => ['message' => 'Rate limit']]);

        $reason = null;
        $llm->on('key.deactivated', function ($p, $k, $r) use (&$reason) {
            $reason = $r;
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertEquals('rate_limit', $reason);
        $status = $llm->getStatus();
        $this->assertEquals('inactive', $status->providers[0]->keys[0]->status);
    }

    public function test_auth_error_immediately_marks_inactive()
    {
        $llm = new OctopusLLM($this->getConfig(['circuitBreaker' => ['failureThreshold' => 5]]));

        // 401 response - should instantly deactivate
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(401, ['error' => ['message' => 'Unauthorized']]);

        $reason = null;
        $llm->on('key.deactivated', function ($p, $k, $r) use (&$reason) {
            $reason = $r;
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertEquals('auth_error', $reason);
        $status = $llm->getStatus();
        $this->assertEquals('inactive', $status->providers[0]->keys[0]->status);
    }

    public function test_cooldown_prevents_premature_retry()
    {
        $llm = new OctopusLLM($this->getConfig());

        // Fast fail and deactivate
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(401, ['error' => ['']]);
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        // Attempt again immediately
        $tried = false;
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, ['choices' => []]);
        $llm->on('provider.exhausted', function () use (&$tried) {
            $tried = true; // Key should be skipped, immediately exhausting the provider without hitting HTTP mock
        });

        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (GatewayExhaustedException $e) {
        }

        $this->assertTrue($tried);
    }

    public function test_half_open_allows_retry_after_cooldown()
    {
        // To test cooldown elapsed, we manipulate the state directly
        $storage = new NullStorage();
        $mockState = [
            'provider1' => [
                'keys' => [
                    ['index' => 0, 'status' => 'inactive', 'failureCount' => 3, 'lastUsed' => null, 'markedAt' => date('c', time() - 10)] // 10 seconds ago
                ]
            ]
        ];

        $storageMock = $this->createMock(\OctopusLLM\Gateway\Contracts\StorageInterface::class);
        $storageMock->method('load')->willReturn($mockState);
        $storageMock->method('save')->willReturnCallback(function ($state) use (&$mockState) {
            $mockState = $state;
        });

        $config = $this->getConfig();
        $config['storage'] = $storageMock;
        $llm = new OctopusLLM($config);

        // This response should be used because cooldown (2s) is less than 10s elapsed
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Success']]]
        ]);

        $res = $llm->chat([['role' => 'user', 'content' => 'hello']]);

        $this->assertEquals('Success', $res->content);
        $this->assertEquals('active', $mockState['provider1']['keys'][0]['status']);
    }
}
