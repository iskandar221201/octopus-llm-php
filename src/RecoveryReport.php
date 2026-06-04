<?php

namespace OctopusLLM\Gateway;

readonly class RecoveryReport
{
    public function __construct(
        public array $recovered,
        public array $failed,
        public int $total,
    ) {
    }
}
