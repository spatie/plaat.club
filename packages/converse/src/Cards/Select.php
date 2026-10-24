<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Collection;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Select implements ActionElement
{
    public const int MAX_OPTIONS = 100;

    protected ?string $label = null;

    protected ?string $placeholder = null;

    /** @var Collection<int, Option> */
    protected Collection $options;

    public function __construct(protected string $id)
    {
        $this->options = collect();
        $this->validateId();
    }

    public static function make(string $id): static
    {
        return new static($id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function placeholder(): ?string
    {
        return $this->placeholder;
    }

    /** @return Collection<int, Option> */
    public function options(): Collection
    {
        return $this->options->collect();
    }

    public function withLabel(string $label): static
    {
        $select = clone $this;
        $select->label = $label;

        return $select;
    }

    public function withPlaceholder(string $placeholder): static
    {
        $select = clone $this;
        $select->placeholder = $placeholder;

        return $select;
    }

    public function option(string $label, string|int $value, ?string $description = null): static
    {
        $select = clone $this;
        $select->options = $this->options->concat([new Option($label, (string) $value, $description)]);

        return $select;
    }

    public function fallbackText(): string
    {
        return $this->label ?? 'Choose an option';
    }

    public function validate(): void
    {
        $this->validateId();

        if ($this->label === '' || $this->placeholder === '' || $this->options->isEmpty() || $this->options->count() > static::MAX_OPTIONS) {
            throw new InvalidCardException('Portable selects require between 1 and '.static::MAX_OPTIONS.' options and non-empty presentation text.');
        }

        $this->options->each->validate();
    }

    protected function validateId(): void
    {
        if ($this->id === '') {
            throw new InvalidCardException('A portable select ID must not be empty.');
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
        return $renderer->renderSelect($this);
    }
}
