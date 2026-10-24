<?php

declare(strict_types=1);

namespace Laravel\Converse\Scheduling;

use Stringable;

class Destination implements Stringable
{
    public function __construct(protected string $connection, protected string $target) {}

    public function connection(): string
    {
        return $this->connection;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function isUsername(): bool
    {
        return str_starts_with($this->target, '@');
    }

    public function isChannelName(): bool
    {
        return str_starts_with($this->target, '#');
    }

    public function __toString(): string
    {
        return "{$this->connection}:{$this->target}";
    }
}
