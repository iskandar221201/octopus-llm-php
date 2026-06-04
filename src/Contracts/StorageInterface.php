<?php

namespace OctopusLLM\Gateway\Contracts;

interface StorageInterface
{
    /**
     * @return array<string, mixed>
     */
    public function load(): array;

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void;
}
