<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

/** @template TResult */
interface ActionRenderer
{
    /** @return TResult */
    public function renderButton(Button $button): mixed;

    /** @return TResult */
    public function renderLinkButton(LinkButton $button): mixed;

    /** @return TResult */
    public function renderSelect(Select $select): mixed;

    /** @return TResult */
    public function renderRadioSelect(RadioSelect $select): mixed;
}
