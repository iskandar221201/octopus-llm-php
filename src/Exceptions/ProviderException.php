<?php

namespace OctopusLLM\Gateway\Exceptions;

class ProviderException extends \RuntimeException
{
    public readonly string $provider;
    public readonly string $originalMessage;

    public function __construct(string $provider, string $originalMessage, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Provider [{$provider}]: {$originalMessage}", $code, $previous);
        $this->provider = $provider;
        $this->originalMessage = $originalMessage;
    }
}
