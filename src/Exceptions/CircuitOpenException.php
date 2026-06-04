<?php

namespace OctopusLLM\Gateway\Exceptions;

class CircuitOpenException extends ProviderException
{
    public readonly int $keyIndex;
    public readonly string $retryAt;

    public function __construct(string $provider, int $keyIndex, string $retryAt, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($provider, "Circuit open for key index {$keyIndex}, retry at {$retryAt}", $code, $previous);
        $this->keyIndex = $keyIndex;
        $this->retryAt = $retryAt;
    }
}
