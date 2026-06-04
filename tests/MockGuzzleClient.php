<?php
namespace GuzzleHttp;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

class Client implements ClientInterface
{
    public static array $mockResponses = [];
    public static ?\Exception $mockException = null;

    public function __construct(array $config = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (self::$mockException) {
            $e = self::$mockException;
            self::$mockException = null; // reset for next tests
            throw $e;
        }

        $response = array_shift(self::$mockResponses);
        if (!$response) {
            // Default mock success response
            return clone self::createJsonResponse(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Mock']]]]);
        }

        if ($response instanceof \Exception) {
            throw $response;
        }

        return clone $response;
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->sendRequest($request);
    }

    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        return $this->sendRequest(new \GuzzleHttp\Psr7\Request($method, $uri));
    }

    // Helper constructor for tests
    public static function createJsonResponse(int $statusCode, array $data): ResponseInterface
    {
        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($data));
    }
}
