<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Collection;

/** @phpstan-consistent-constructor */
class Section implements CardElement
{
    /** @var Collection<int, CardElement> */
    protected Collection $children;

    public function __construct(CardElement ...$children)
    {
        $this->children = collect($children)->values();
    }

    public static function make(CardElement ...$children): static
    {
        return new static(...$children);
    }

    /** @return Collection<int, CardElement> */
    public function children(): Collection
    {
        return $this->children->collect();
    }

    public function withChildren(CardElement ...$children): static
    {
        $section = clone $this;
        $section->children = collect($children)->values();

        return $section;
    }

    public function add(CardElement $child): static
    {
        return $this->withChildren(...$this->children->concat([$child]));
    }

    public function fallbackText(): string
    {
        return $this->children->map->fallbackText()->filter(fn (string $text): bool => $text !== '')->implode("\n");
    }

    public function validate(): void
    {
        $this->children->each->validate();
    }

    /**
     * @template TResult
     *
     * @param  CardRenderer<TResult>  $renderer
     * @return TResult
     */
    public function renderWith(CardRenderer $renderer): mixed
    {
        return $renderer->renderSection($this);
    }
}
