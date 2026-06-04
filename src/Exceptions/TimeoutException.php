<?php

namespace OctopusLLM\Gateway\Exceptions;

class TimeoutException extends ProviderException
{
    public readonly int $timeoutSeconds;

    public function __construct(string $provider, int $timeoutSeconds, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($provider, "Request timed out after {$timeoutSeconds}s", $code, $previous);
        $this->timeoutSeconds = $timeoutSeconds;
    }
}
