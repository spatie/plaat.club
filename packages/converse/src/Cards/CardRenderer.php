<?php

declare(strict_types=1);

namespace Laravel\Converse\Cards;

/** @template TResult */
interface CardRenderer
{
    /** @return TResult */
    public function renderText(Text $text): mixed;

    /** @return TResult */
    public function renderImage(Image $image): mixed;

    /** @return TResult */
    public function renderDivider(Divider $divider): mixed;

    /** @return TResult */
    public function renderLink(Link $link): mixed;

    /** @return TResult */
    public function renderFields(Fields $fields): mixed;

    /** @return TResult */
    public function renderTable(Table $table): mixed;

    /** @return TResult */
    public function renderSection(Section $section): mixed;

    /** @return TResult */
    public function renderActions(Actions $actions): mixed;
}
