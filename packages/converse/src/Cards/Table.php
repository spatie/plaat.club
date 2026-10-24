<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Illuminate\Support\Collection;
use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class Table implements CardElement
{
    public const int MAX_COLUMNS = 20;

    public const int MAX_ROWS = 100;

    /** @var array<int, string> */
    protected array $headers = [];

    /** @var Collection<int, array<int, string>> */
    protected Collection $rows;

    protected ?string $caption = null;

    public function __construct()
    {
        $this->rows = collect();
    }

    public static function make(): static
    {
        return new static;
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return Collection<int, array<int, string>> */
    public function rows(): Collection
    {
        return $this->rows->collect();
    }

    public function caption(): ?string
    {
        return $this->caption;
    }

    /** @param  array<int, string>  $headers */
    public function withHeaders(array $headers): static
    {
        $table = clone $this;
        $table->headers = array_values($headers);

        return $table;
    }

    /** @param  array<int, string>  $row */
    public function row(array $row): static
    {
        $table = clone $this;
        $table->rows = $this->rows->concat([array_values($row)]);

        return $table;
    }

    public function withCaption(string $caption): static
    {
        $table = clone $this;
        $table->caption = $caption;

        return $table;
    }

    public function fallbackText(): string
    {
        return $this->rows()
            ->prepend($this->headers)
            ->map(fn (array $row): string => implode(' | ', $row))
            ->implode("\n");
    }

    public function validate(): void
    {
        $columns = count($this->headers);

        if ($columns === 0 || $columns > static::MAX_COLUMNS || $this->rows->count() > static::MAX_ROWS || in_array('', $this->headers, true)) {
            throw new InvalidCardException('Portable tables require between 1 and '.static::MAX_COLUMNS.' non-empty headers and at most '.static::MAX_ROWS.' rows.');
        }

        if ($this->rows->contains(fn (array $row): bool => count($row) !== $columns)) {
            throw new InvalidCardException('Every portable table row must match the header column count.');
        }

        if ($this->caption === '') {
            throw new InvalidCardException('Portable table captions must not be empty.');
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
        return $renderer->renderTable($this);
    }
}
