<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Str;
use Laravel\Converse\Exceptions\InvalidCardException;

class Option
{
    public const int MAX_LENGTH = 75;

    public function __construct(protected string $label, protected string $value, protected ?string $description = null)
    {
        $this->validate();
    }

    public function label(): string
    {
        return $this->label;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function validate(): void
    {
        if ($this->label === '' || $this->value === '' || $this->description === '') {
            throw new InvalidCardException('Portable select option labels and values must not be empty.');
        }

        if (Str::length($this->label) > static::MAX_LENGTH || Str::length($this->value) > static::MAX_LENGTH || Str::length((string) $this->description) > static::MAX_LENGTH) {
            throw new InvalidCardException('Portable select option text must not exceed '.static::MAX_LENGTH.' characters.');
        }
    }
}
