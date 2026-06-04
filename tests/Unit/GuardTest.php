<?php
namespace OctopusLLM\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OctopusLLM\Gateway\OctopusLLM;
use OctopusLLM\Gateway\Exceptions\InputTooLongException;

class GuardTest extends TestCase
{
    private function getOctopus(int $maxTokens): OctopusLLM
    {
        return new OctopusLLM([
            'providers' => [
                ['id' => 'p', 'priority' => 1, 'baseURL' => 'http://test', 'model' => 'm', 'keys' => ['k']]
            ],
            'guard' => [
                'maxInputTokens' => $maxTokens,
            ]
        ]);
    }

    public function test_throws_input_too_long_for_excessive_tokens()
    {
        $llm = $this->getOctopus(10); // max 10 tokens

        // 10 tokens = 40 chars. So 41 chars should throw.
        $messages = [['role' => 'user', 'content' => str_repeat('a', 41)]];

        $this->expectException(InputTooLongException::class);
        $llm->chat($messages);
    }

    public function test_allows_input_within_token_limit()
    {
        $llm = $this->getOctopus(10); // max 10 tokens

        // 40 chars = 10 tokens
        $messages = [['role' => 'user', 'content' => str_repeat('a', 40)]];

        // Should not throw InputTooLongException
        // Will throw GatewayExhaustedException since mock isn't handling it, but that means it passed the guard.
        try {
            $llm->chat($messages);
            $this->fail('Expected connection to fail');
        } catch (\OctopusLLM\Gateway\Exceptions\GatewayExhaustedException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_token_estimation_uses_4_chars_per_token()
    {
        $llm = $this->getOctopus(5);

        // 17 characters / 4 = 4.25 -> ceil = 5 tokens
        $messages = [['role' => 'user', 'content' => str_repeat('a', 17)]];
        try {
            $llm->chat($messages);
            $this->assertTrue(true);
        } catch (InputTooLongException $e) {
            $this->fail('17 characters should be 5 tokens and not exceed the limit of 5.');
        } catch (\Exception $e) {
        }

        // 21 characters / 4 = 5.25 -> ceil = 6 tokens -> Exceeds limit 5
        $messages = [['role' => 'user', 'content' => str_repeat('a', 21)]];
        try {
            $llm->chat($messages);
            $this->fail('21 characters should be 6 tokens and exceed the limit of 5.');
        } catch (InputTooLongException $e) {
            $this->assertEquals(6, $e->estimated);
        }
    }

    public function test_exception_contains_estimated_and_limit()
    {
        $llm = $this->getOctopus(100);

        // 401 chars / 4 = 100.25 -> ceil = 101 tokens
        $messages = [['role' => 'user', 'content' => str_repeat('x', 401)]];

        try {
            $llm->chat($messages);
            $this->fail('Expected exception');
        } catch (InputTooLongException $e) {
            $this->assertEquals(101, $e->estimated);
            $this->assertEquals(100, $e->limit);
            $this->assertStringContainsString('limit 100', $e->getMessage());
        }
    }
}
