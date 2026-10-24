<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Str;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Link implements CardElement
{
    public function __construct(protected string $label, protected string $url)
    {
        $this->validate();
    }

    public static function make(string $label, string $url): static
    {
        return new static($label, $url);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function fallbackText(): string
    {
        return "{$this->label}: {$this->url}";
    }

    public function validate(): void
    {
        if ($this->label === '' || ! Str::startsWith($this->url, ['http://', 'https://'])) {
            throw new InvalidCardException('A portable card link requires a label and an absolute HTTP URL.');
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
        return $renderer->renderLink($this);
    }
}
