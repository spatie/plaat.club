<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Collection;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Fields implements CardElement
{
    public const int MAX_FIELDS = 25;

    /** @var Collection<int, Field> */
    protected Collection $children;

    public function __construct(Field ...$fields)
    {
        $this->children = collect($fields)->values();
        $this->validate();
    }

    public static function make(Field ...$fields): static
    {
        return new static(...$fields);
    }

    /** @return Collection<int, Field> */
    public function children(): Collection
    {
        return $this->children->collect();
    }

    public function fallbackText(): string
    {
        return $this->children->map->fallbackText()->implode("\n");
    }

    public function validate(): void
    {
        if ($this->children->isEmpty() || $this->children->count() > static::MAX_FIELDS) {
            throw new InvalidCardException('Portable card field groups require between 1 and '.static::MAX_FIELDS.' fields.');
        }

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
        return $renderer->renderFields($this);
    }
}
