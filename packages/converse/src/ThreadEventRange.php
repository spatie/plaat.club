<?php

declare(strict_types=1);

namespace Laravel\Converse;

class ThreadEventRange
{
    public function __construct(
        protected int $offset,
        protected int $lastSequence,
    ) {}

    public function offset(): int
    {
        return $this->offset;
    }

    public function lastSequence(): int
    {
        return $this->lastSequence;
    }
}
