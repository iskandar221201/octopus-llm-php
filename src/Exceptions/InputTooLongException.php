<?php

namespace OctopusLLM\Gateway\Exceptions;

class InputTooLongException extends \InvalidArgumentException
{
    public readonly int $estimated;
    public readonly int $limit;

    public function __construct(int $estimated, int $limit, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Input too long: estimated {$estimated} tokens, limit is {$limit}", $code, $previous);
        $this->estimated = $estimated;
        $this->limit = $limit;
    }
}
