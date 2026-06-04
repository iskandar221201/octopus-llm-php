<?php

namespace OctopusLLM\Gateway\Exceptions;

class GatewayExhaustedException extends \RuntimeException
{
    public function __construct(string $message = 'All providers and keys are exhausted.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
