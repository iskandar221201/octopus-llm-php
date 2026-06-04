<?php

namespace OctopusLLM\Gateway\Exceptions;

class AuthenticationException extends ProviderException
{
    public readonly int $keyIndex;

    public function __construct(string $provider, int $keyIndex, int $code = 401, ?\Throwable $previous = null)
    {
        parent::__construct($provider, "Authentication failed for key index {$keyIndex}", $code, $previous);
        $this->keyIndex = $keyIndex;
    }
}
