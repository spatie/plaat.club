<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Stringable;

class Markdown implements Stringable
{
    public function __construct(protected string $content) {}

    public static function make(string $content): static
    {
        return new static($content);
    }

    public function content(): string
    {
        return $this->content;
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
