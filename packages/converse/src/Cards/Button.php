<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Str;
use Laravel\Converse\Enums\ButtonStyle;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Button implements ActionElement
{
    protected string $label;

    protected ?string $value = null;

    protected ?string $callbackUrl = null;

    protected ButtonStyle $style = ButtonStyle::Default;

    protected bool $once = false;

    public function __construct(protected string $id)
    {
        $this->label = $id;
        $this->validate();
    }

    public static function make(string $id): static
    {
        return new static($id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function callbackUrl(): ?string
    {
        return $this->callbackUrl;
    }

    public function style(): ButtonStyle
    {
        return $this->style;
    }

    public function isOnce(): bool
    {
        return $this->once;
    }

    public function withLabel(string $label): static
    {
        $button = clone $this;
        $button->label = $label;

        return $button;
    }

    public function withValue(string|int $value): static
    {
        $button = clone $this;
        $button->value = (string) $value;

        return $button;
    }

    public function withCallbackUrl(string $url): static
    {
        $button = clone $this;
        $button->callbackUrl = $url;
        $button->validate();

        return $button;
    }

    public function once(bool $once = true): static
    {
        $button = clone $this;
        $button->once = $once;

        return $button;
    }

    public function primary(): static
    {
        return $this->withStyle(ButtonStyle::Primary);
    }

    public function danger(): static
    {
        return $this->withStyle(ButtonStyle::Danger);
    }

    protected function withStyle(ButtonStyle $style): static
    {
        $button = clone $this;
        $button->style = $style;

        return $button;
    }

    public function fallbackText(): string
    {
        return $this->label;
    }

    public function validate(): void
    {
        if ($this->id === '') {
            throw new InvalidCardException('A portable card action ID must not be empty.');
        }

        if ($this->label === '') {
            throw new InvalidCardException('A portable card action label must not be empty.');
        }

        if ($this->callbackUrl !== null && ! Str::startsWith($this->callbackUrl, 'https://')) {
            throw new InvalidCardException('A portable card callback URL must be an absolute HTTPS URL.');
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
        return $renderer->renderButton($this);
    }
}
