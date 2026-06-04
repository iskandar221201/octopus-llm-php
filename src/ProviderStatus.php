<?php

namespace OctopusLLM\Gateway;

readonly class ProviderStatus
{
    /**
     * @param KeyStatus[] $keys
     */
    public function __construct(
        public string $id,
        public int $priority,
        public array $keys,
    ) {
    }
}
