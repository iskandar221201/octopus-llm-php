<?php
namespace OctopusLLM\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OctopusLLM\Gateway\OctopusLLM;
use OctopusLLM\Gateway\ChatResponse;
use OctopusLLM\Gateway\Storage\NullStorage;
use OctopusLLM\Gateway\Exceptions\GatewayExhaustedException;
use GuzzleHttp\Client as MockGuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

class ChatTest extends TestCase
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
                [
                    'id' => 'p1',
                    'priority' => 1,
                    'baseURL' => 'http://p1',
                    'model' => 'model-p1',
                    'keys' => ['k1'],
                ],
                [
                    'id' => 'p2',
                    'priority' => 2,
                    'baseURL' => 'http://p2',
                    'model' => 'model-p2',
                    'keys' => ['k2'],
                ]
            ],
            'guard' => [
                'timeoutSeconds' => 1,
                'maxRetries' => 1, // Will retry once per key
            ],
            'circuitBreaker' => [
                'failureThreshold' => 5,
            ],
            'storage' => new NullStorage()
        ], $overrides);
    }

    public function test_successful_chat_returns_chat_response()
    {
        $llm = new OctopusLLM($this->getConfig());

        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello World']]]
        ]);

        $response = $llm->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertInstanceOf(ChatResponse::class, $response);
        $this->assertEquals('Hello World', $response->content);
        $this->assertEquals('p1', $response->providerId);
        $this->assertEquals(0, $response->keyIndex); // First key
        $this->assertEquals('model-p1', $response->model);
        $this->assertFalse($response->fallbackUsed);
        $this->assertEquals(1, $response->attempts);
        $this->assertGreaterThanOrEqual(0, $response->latencyMs);
    }

    public function test_retry_on_transient_failure()
    {
        $llm = new OctopusLLM($this->getConfig());

        // Setup transient failure then success on the same key
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout', new Request('POST', 'test'));
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Success on retry']]]
        ]);

        $response = $llm->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('Success on retry', $response->content);
        $this->assertEquals(2, $response->attempts);
        $this->assertFalse($response->fallbackUsed);
    }

    public function test_throws_gateway_exhausted_when_all_fail()
    {
        $llm = new OctopusLLM($this->getConfig());

        // Provider 1 (key 1) -> 2 attempts (initial + 1 retry)
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout', new Request('POST', 'test'));
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout', new Request('POST', 'test'));

        // Provider 2 (key 2) -> 2 attempts
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout', new Request('POST', 'test'));
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout', new Request('POST', 'test'));

        $this->expectException(GatewayExhaustedException::class);
        $llm->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_streaming_calls_on_chunk()
    {
        $llm = new OctopusLLM($this->getConfig());

        // Mock a streaming response format. 
        // Note: openai-php/client parses Server-Sent Events (SSE). 
        // We simulate the plain text stream here.
        $streamContent = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello \"}}]}\n\n" .
            "data: {\"choices\":[{\"delta\":{\"content\":\"Streaming\"}}]}\n\n" .
            "data: [DONE]\n\n";

        MockGuzzleClient::$mockResponses[] = new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $streamContent
        );

        $chunks = [];
        $response = $llm->chat([['role' => 'user', 'content' => 'Hi']], [
            'stream' => true,
            'onChunk' => function ($content) use (&$chunks) {
                $chunks[] = $content;
            }
        ]);

        $this->assertEquals('Hello Streaming', $response->content);
        $this->assertEquals(['Hello ', 'Streaming'], $chunks);
    }

    public function test_force_provider_bypasses_rotation()
    {
        $llm = new OctopusLLM($this->getConfig());

        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Provider 2 forced']]]
        ]);

        $response = $llm->chat([['role' => 'user', 'content' => 'Hi']], [
            'forceProvider' => 'p2'
        ]);

        $this->assertEquals('p2', $response->providerId);
        $this->assertEquals('model-p2', $response->model);
        $this->assertFalse($response->fallbackUsed); // Although p2 is lower priority, it was forced, so fallback Used should technically strictly be true if index > 0.
        // Wait, index in array_values is 0 for forced! 
        // $providers = array_values($providers); so providerIndex is 0.
    }

    public function test_fallback_used_flag_is_true_when_fallback()
    {
        $llm = new OctopusLLM($this->getConfig());

        // P1 fails all retries
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout p1', new Request('POST', 'test'));
        MockGuzzleClient::$mockResponses[] = new ConnectException('Timeout p1', new Request('POST', 'test'));

        // P2 succeeds
        MockGuzzleClient::$mockResponses[] = MockGuzzleClient::createJsonResponse(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Provider 2 fallback']]]
        ]);

        $response = $llm->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('p2', $response->providerId);
        $this->assertTrue($response->fallbackUsed);
    }
}
