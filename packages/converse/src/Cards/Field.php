<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Field
{
    public function __construct(protected string $label, protected string $value)
    {
        $this->validate();
    }

    public static function make(string $label, string $value): static
    {
        return new static($label, $value);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function fallbackText(): string
    {
        return "{$this->label}: {$this->value}";
    }

    public function validate(): void
    {
        if ($this->label === '' || $this->value === '') {
            throw new InvalidCardException('Portable card field labels and values must not be empty.');
        }
    }
}
