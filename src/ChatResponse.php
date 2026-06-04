<?php

namespace OctopusLLM\Gateway;

readonly class ChatResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public int $keyIndex,
        public string $model,
        public float $latencyMs,
        public int $attempts,
        public bool $fallbackUsed,
    ) {
    }
}
