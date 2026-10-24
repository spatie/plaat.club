<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

/** @phpstan-consistent-constructor */
class Divider implements CardElement
{
    public static function make(): static
    {
        return new static;
    }

    public function fallbackText(): string
    {
        return '';
    }

    public function validate(): void {}

    /**
     * @template TResult
     *
     * @param  CardRenderer<TResult>  $renderer
     * @return TResult
     */
    public function renderWith(CardRenderer $renderer): mixed
    {
        return $renderer->renderDivider($this);
    }
}
