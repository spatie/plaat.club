<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

use Laravel\Converse\Exceptions\InvalidCardException;

/** @phpstan-consistent-constructor */
class RadioSelect extends Select
{
    public const int MAX_RADIO_OPTIONS = 10;

    public function validate(): void
    {
        parent::validate();

        if ($this->options->count() > static::MAX_RADIO_OPTIONS) {
            throw new InvalidCardException('Portable radio selects support at most '.static::MAX_RADIO_OPTIONS.' options.');
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
        return $renderer->renderRadioSelect($this);
    }
}
