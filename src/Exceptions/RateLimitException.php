<?php

namespace OctopusLLM\Gateway\Exceptions;

class RateLimitException extends ProviderException
{
    public readonly ?int $retryAfter;

    public function __construct(string $provider, string $originalMessage, ?int $retryAfter = null, int $code = 429, ?\Throwable $previous = null)
    {
        parent::__construct($provider, $originalMessage, $code, $previous);
        $this->retryAfter = $retryAfter;
    }
}
