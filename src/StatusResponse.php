<?php

namespace OctopusLLM\Gateway;

readonly class StatusResponse
{
    /**
     * @param ProviderStatus[] $providers
     */
    public function __construct(
        public array $providers,
        public int $totalActive,
        public int $totalInactive,
    ) {
    }
}
