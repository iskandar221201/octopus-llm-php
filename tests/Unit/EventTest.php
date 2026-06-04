<?php
namespace OctopusLLM\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OctopusLLM\Gateway\OctopusLLM;
use OctopusLLM\Gateway\Storage\NullStorage;
use OctopusLLM\Gateway\Exceptions\GatewayExhaustedException;
use GuzzleHttp\Client as MockGuzzleClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;

class EventTest extends TestCase
{
    protected function setUp(): void
    {
        MockGuzzleClient::$mockResponses = [];
        MockGuzzleClient::$mockException = null;
    }

    private function getConfig(array $overrides = []): array
    {
        return array_merge([
            'providers' => [
                ['id' => 'p1', 'priority' => 1, 'baseURL' => 'http://p1', 'model' => 'm', 'keys' => ['k1']],
                ['id' => 'p2', 'priority' => 2, 'baseURL' => 'http://p2', 'model' => 'm', 'keys' => ['k2']],
            ],
            'guard' => ['timeoutSeconds' => 1, 'maxRetries' => 0],
            'circuitBreaker' => ['failureThreshold' => 1],
            'storage' => clone new NullStorage()
        ], $overrides);
    }

    public function test_registers_event_listener()
    {
        $llm = new OctopusLLM($this->getConfig());

        $returned = $llm->on('fallback', function () {});

        $this->assertSame($llm, $returned);
    }

    public function test_emits_key_deactivated_event()
    {
        $llm = new OctopusLLM($this->getConfig());

        $called = false;
        $llm->on('key.deactivated', function ($providerId, $keyIndex, $reason) use (&$called) {
            $this->assertEquals('p1', $providerId);
            $this->assertEquals(0, $keyIndex);
            $this->assertEquals('timeout', $reason);
            $called = true;
        });

        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (\Exception $e) {
        }

        $this->assertTrue($called);
    }

    public function test_emits_provider_exhausted_event()
    {
        $llm = new OctopusLLM($this->getConfig());

        $called = false;
        $llm->on('provider.exhausted', function ($providerId) use (&$called) {
            if ($providerId === 'p1') {
                $called = true;
            }
        });

        // Fail p1
        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (\Exception $e) {
        }

        $this->assertTrue($called);
    }

    public function test_emits_fallback_event()
    {
        $llm = new OctopusLLM($this->getConfig());

        $called = false;
        $llm->on('fallback', function ($currentProvider, $nextProvider) use (&$called) {
            $this->assertEquals('p1', $currentProvider);
            $this->assertEquals('p2', $nextProvider);
            $called = true;
        });

        // Fail p1 so it falls back to p2
        MockGuzzleClient::$mockException = new ConnectException('Timeout', new Request('POST', 'test'));
        try {
            $llm->chat([['role' => 'user', 'content' => 'test']]);
        } catch (\Exception $e) {
        }

        $this->assertTrue($called);
    }

    public function test_emits_key_recovered_event()
    {
        // Setup state to have inactive key
        $storage = new NullStorage();
        $mockState = [
            'p1' => [
                'keys' => [
                    ['index' => 0, 'status' => 'inactive', 'failureCount' => 1, 'lastUsed' => null, 'markedAt' => date('c', time() - 3600)]
                ]
            ]
        ];

        $storageMock = $this->createMock(\OctopusLLM\Gateway\Contracts\StorageInterface::class);
        $storageMock->method('load')->willReturn($mockState);

        $config = $this->getConfig(['storage' => $storageMock]);
        $llm = new OctopusLLM($config);

        $called = false;
        $llm->on('key.recovered', function ($providerId, $keyIndex) use (&$called) {
            $this->assertEquals('p1', $providerId);
            $this->assertEquals(0, $keyIndex);
            $called = true;
        });

        // Success on ping model list
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, ['data' => []]);

        $report = $llm->runRecovery();

        $this->assertTrue($called);
        $this->assertCount(1, $report->recovered);
    }

    public function test_invalid_event_name_throws_exception()
    {
        $llm = new OctopusLLM($this->getConfig());

        $this->expectException(\InvalidArgumentException::class);
        $llm->on('invalid_event.name', function () {});
    }
}
