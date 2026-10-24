<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

interface CardElement
{
    public function fallbackText(): string;

    public function validate(): void;

    /**
     * @template TResult
     *
     * @param  CardRenderer<TResult>  $renderer
     * @return TResult
     */
    public function renderWith(CardRenderer $renderer): mixed;
}
