<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

class TerminalAction
{
    public function __construct(
        protected string $label,
        protected ?string $id = null,
        protected ?string $value = null,
        protected ?string $url = null,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function url(): ?string
    {
        return $this->url;
    }
}
