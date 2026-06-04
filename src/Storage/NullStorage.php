<?php

namespace OctopusLLM\Gateway\Storage;

use OctopusLLM\Gateway\Contracts\StorageInterface;

class NullStorage implements StorageInterface
{
    public function load(): array
    {
        return [];
    }

    public function save(array $state): void
    {
    }
}
