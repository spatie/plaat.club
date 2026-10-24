<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Text implements CardElement
{
    public function __construct(protected string $text, protected bool $raw = false)
    {
        $this->validate();
    }

    public static function make(string $text): static
    {
        return new static($text);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isRaw(): bool
    {
        return $this->raw;
    }

    public function asRaw(bool $raw = true): static
    {
        $text = clone $this;
        $text->raw = $raw;

        return $text;
    }

    public function fallbackText(): string
    {
        return $this->text;
    }

    public function validate(): void
    {
        if ($this->text === '') {
            throw new InvalidCardException('Portable card text must not be empty.');
        }
    }

    /**
     * @template TResult
     *
     * @param  CardRenderer<TResult>  $renderer
     * @return TResult
     */
    public function renderWith(CardRenderer $renderer): mixed
    {
        return $renderer->renderText($this);
    }
}
