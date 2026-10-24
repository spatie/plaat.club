<?php

declare(strict_types=1);

namespace Laravel\Converse;

class RunState
{
    public function __construct(
        protected string $id,
        protected int $offset,
        protected ?string $promptMessageId = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function promptMessageId(): ?string
    {
        return $this->promptMessageId;
    }
}
