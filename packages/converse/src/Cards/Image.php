<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Str;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Image implements CardElement
{
    public function __construct(protected string $url, protected ?string $alt = null)
    {
        $this->validate();
    }

    public static function make(string $url, ?string $alt = null): static
    {
        return new static($url, $alt);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function fallbackText(): string
    {
        return $this->alt ?? $this->url;
    }

    public function validate(): void
    {
        if (! Str::startsWith($this->url, ['http://', 'https://'])) {
            throw new InvalidCardException('A portable card image URL must be an absolute HTTP URL.');
        }

        if ($this->alt === '') {
            throw new InvalidCardException('Portable card image alt text must not be empty.');
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
        return $renderer->renderImage($this);
    }
}
