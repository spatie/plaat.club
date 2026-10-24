<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Collection;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Card
{
    /** @var Collection<int, CardElement> */
    protected Collection $children;

    protected ?string $subtitle = null;

    protected ?string $fallback = null;

    /** @var array<string, array<string, mixed>> */
    protected array $raw = [];

    public function __construct(protected ?string $title = null)
    {
        $this->children = collect();
    }

    public static function make(?string $title = null): static
    {
        return new static($title);
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function subtitle(): ?string
    {
        return $this->subtitle;
    }

    /** @return Collection<int, CardElement> */
    public function children(): Collection
    {
        return $this->children->collect();
    }

    public function fallback(): ?string
    {
        return $this->fallback;
    }

    /** @return array<string, array<string, mixed>> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function withSubtitle(string $subtitle): static
    {
        $card = clone $this;
        $card->subtitle = $subtitle;

        return $card;
    }

    public function withChildren(CardElement ...$children): static
    {
        $card = clone $this;
        $card->children = collect($children)->values();

        return $card;
    }

    public function add(CardElement $child): static
    {
        return $this->withChildren(...$this->children->concat([$child]));
    }

    public function withFallback(string $fallback): static
    {
        $card = clone $this;
        $card->fallback = $fallback;

        return $card;
    }

    /** @param  array<string, mixed>  $payload */
    public function withRaw(string $platform, array $payload): static
    {
        $card = clone $this;
        $card->raw = [...$this->raw, $platform => $payload];

        return $card;
    }

    public function fallbackText(): string
    {
        return $this->fallback ?? $this->title ?? $this->children
            ->map(fn (CardElement $child): string => $child->fallbackText())
            ->first(fn (string $text): bool => $text !== '') ?? '';
    }

    public function validate(): void
    {
        foreach (['title' => $this->title, 'subtitle' => $this->subtitle, 'fallback' => $this->fallback] as $name => $value) {
            if ($value === '') {
                throw new InvalidCardException("Card [{$name}] must not be empty.");
            }
        }

        $this->children->each->validate();
    }
}
