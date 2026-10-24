<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

interface ActionElement
{
    public function fallbackText(): string;

    public function validate(): void;

    /**
     * @template TResult
     *
     * @param  ActionRenderer<TResult>  $renderer
     * @return TResult
     */
    public function renderWith(ActionRenderer $renderer): mixed;
}
