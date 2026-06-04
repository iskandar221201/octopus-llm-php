<?php

namespace OctopusLLM\Gateway;

readonly class KeyStatus
{
    public function __construct(
        public int $index,
        public string $status,
        public int $failureCount,
        public ?string $lastUsed,
        public ?string $markedAt,
    ) {
    }
}
