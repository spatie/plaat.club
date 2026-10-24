<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Collection;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Actions implements CardElement
{
    public const int MAX_CONTROLS = 25;

    /** @var Collection<int, ActionElement> */
    protected Collection $children;

    public function __construct(ActionElement ...$children)
    {
        $this->children = collect($children)->values();
        $this->validate();
    }

    public static function make(ActionElement ...$children): static
    {
        return new static(...$children);
    }

    /** @return Collection<int, ActionElement> */
    public function children(): Collection
    {
        return $this->children->collect();
    }

    public function withChildren(ActionElement ...$children): static
    {
        $actions = clone $this;
        $actions->children = collect($children)->values();
        $actions->validate();

        return $actions;
    }

    public function fallbackText(): string
    {
        return 'Choose an action';
    }

    public function validate(): void
    {
        if ($this->children->isEmpty() || $this->children->count() > static::MAX_CONTROLS) {
            throw new InvalidCardException('Portable card action groups require between 1 and '.static::MAX_CONTROLS.' controls.');
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
        return $renderer->renderActions($this);
    }
}
