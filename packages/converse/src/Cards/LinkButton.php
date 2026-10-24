<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Str;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class LinkButton implements ActionElement
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
        if ($this->label === '') {
            throw new InvalidCardException('A portable link button label must not be empty.');
        }

        if (! Str::startsWith($this->url, ['http://', 'https://'])) {
            throw new InvalidCardException('A portable link button URL must be an absolute HTTP URL.');
        }
    }

    /**
     * @template TResult
     *
     * @param  ActionRenderer<TResult>  $renderer
     * @return TResult
     */
    public function renderWith(ActionRenderer $renderer): mixed
    {
        return $renderer->renderLinkButton($this);
    }
}
