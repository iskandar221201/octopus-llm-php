<?php
// PHP simple mock server for Guzzle requests
$uri = $_SERVER['REQUEST_URI'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$headerAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

header('Content-Type: application/json');

if (strpos($uri, '/chat/completions') !== false) {
    if (strpos($headerAuth, 'key-rate-limit') !== false) {
        http_response_code(429);
        echo json_encode(['error' => ['message' => 'Rate limit exceeded']]);
        exit;
    }

    if (strpos($headerAuth, 'key-auth-error') !== false) {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'Unauthorized']]);
        exit;
    }

    if (strpos($headerAuth, 'key-success') !== false) {
        http_response_code(200);
        echo json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'mock-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello from mock server'
                    ],
                    'finish_reason' => 'stop'
                ]
            ]
        ]);
        exit;
    }
}

// Fallback error for unhandled routes
http_response_code(400);
echo json_encode(['error' => ['message' => 'Bad Request to mock server']]);
exit;
