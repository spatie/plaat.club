<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

use Illuminate\Support\Collection;
use Laravel\Converse\Cards\ActionElement;
use Laravel\Converse\Cards\ActionRenderer;
use Laravel\Converse\Cards\Actions;
use Laravel\Converse\Cards\Button;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\CardElement;
use Laravel\Converse\Cards\CardRenderer;
use Laravel\Converse\Cards\Divider;
use Laravel\Converse\Cards\Field;
use Laravel\Converse\Cards\Fields;
use Laravel\Converse\Cards\Image;
use Laravel\Converse\Cards\Link;
use Laravel\Converse\Cards\LinkButton;
use Laravel\Converse\Cards\Option;
use Laravel\Converse\Cards\RadioSelect;
use Laravel\Converse\Cards\Section;
use Laravel\Converse\Cards\Select;
use Laravel\Converse\Cards\Table;
use Laravel\Converse\Cards\Text;
use Laravel\Converse\Markdown;

/**
 * @implements CardRenderer<string>
 * @implements ActionRenderer<Collection<int, TerminalAction>>
 */
class TerminalRenderer implements ActionRenderer, CardRenderer
{
    /** @var Collection<int, TerminalAction> */
    protected Collection $actions;

    public function __construct(protected int $width)
    {
        $this->actions = collect();
    }

    public static function render(Card|Markdown|string $content, int $width): string
    {
        if ($content instanceof Markdown) {
            return MarkdownRenderer::render($content->content(), $width);
        }

        if (is_string($content)) {
            return static::text($content, $width);
        }

        $renderer = new static($width);

        $blocks = collect([static::header($content, $width)])
            ->concat($renderer->elements($content->children()))
            ->push(static::controls($renderer->actions))
            ->filter(fn (string $block): bool => $block !== '');

        return $blocks->isEmpty()
            ? static::text($content->fallbackText(), $width)
            : $blocks->implode(Html::gap());
    }

    /** @return Collection<int, TerminalAction> */
    public static function actions(Card $card): Collection
    {
        $renderer = new static(PHP_INT_MAX);

        $renderer->elements($card->children());

        return $renderer->actions;
    }

    public function renderText(Text $text): string
    {
        return $text->isRaw()
            ? static::text($text->text(), $this->width)
            : MarkdownRenderer::render($text->text(), $this->width);
    }

    public function renderDivider(Divider $divider): string
    {
        return Html::block('<hr/>', 'text-gray');
    }

    public function renderImage(Image $image): string
    {
        return Html::block('<span class="bg-gray-700 text-white px-1">image</span>'
            .'<span class="ml-1 text-gray">'.Html::escape($image->fallbackText()).'</span>', 'flex');
    }

    public function renderLink(Link $link): string
    {
        return Html::block('<a href="'.Html::escape($link->url()).'">'.Html::escape($link->label()).'</a>'
            .'<span class="ml-1 text-gray">'.Html::escape($link->url()).'</span>', 'flex');
    }

    public function renderFields(Fields $fields): string
    {
        $label = (int) $fields->children()->map(fn (Field $field): int => mb_strwidth($field->label()))->max();

        return $fields->children()->map(fn (Field $field): string => Html::block(
            '<span class="text-gray">'.Html::escape(Html::pad($field->label(), $label)).'</span>'
                .'<span class="ml-2">'.Html::escape($field->value()).'</span>',
            'flex',
        ))->implode('');
    }

    public function renderTable(Table $table): string
    {
        $widths = static::widths($table);

        $rule = collect($widths)->map(fn (int $width): string => str_repeat('─', $width))->implode('   ');

        return collect([Html::block(Html::escape(static::row($table->headers(), $widths)), 'font-bold')])
            ->push(Html::block(Html::escape($rule), 'text-gray'))
            ->concat($table->rows()->map(fn (array $row): string => Html::block(Html::escape(static::row($row, $widths)))))
            ->push($table->caption() === null ? '' : Html::block(Html::escape($table->caption()), 'text-gray'))
            ->filter(fn (string $line): bool => $line !== '')
            ->implode('');
    }

    public function renderSection(Section $section): string
    {
        return $this->elements($section->children())
            ->filter(fn (string $block): bool => $block !== '')
            ->implode(Html::gap());
    }

    public function renderActions(Actions $actions): string
    {
        $this->actions = $this->actions->concat($actions->children()->flatMap(fn (ActionElement $action): Collection => $action->renderWith($this)));

        return '';
    }

    /** @return Collection<int, TerminalAction> */
    public function renderButton(Button $button): Collection
    {
        return collect([new TerminalAction($button->label(), $button->id(), $button->value())]);
    }

    /** @return Collection<int, TerminalAction> */
    public function renderLinkButton(LinkButton $button): Collection
    {
        return collect([new TerminalAction($button->label(), url: $button->url())]);
    }

    /** @return Collection<int, TerminalAction> */
    public function renderSelect(Select $select): Collection
    {
        return $select->options()->map(fn (Option $option): TerminalAction => new TerminalAction(
            $select->label() === null ? $option->label() : $select->label().': '.$option->label(),
            $select->id(),
            $option->value(),
        ));
    }

    /** @return Collection<int, TerminalAction> */
    public function renderRadioSelect(RadioSelect $select): Collection
    {
        return $this->renderSelect($select);
    }

    /**
     * @param  Collection<int, CardElement>  $elements
     * @return Collection<int, string>
     */
    protected function elements(Collection $elements): Collection
    {
        return $elements->map(fn (CardElement $element): string => $element->renderWith($this));
    }

    protected static function header(Card $card, int $width): string
    {
        return collect([
            $card->title() === null ? '' : static::text($card->title(), $width, 'font-bold'),
            $card->subtitle() === null ? '' : static::text($card->subtitle(), $width, 'text-gray'),
        ])->filter(fn (string $line): bool => $line !== '')->implode('');
    }

    /** @param  Collection<int, TerminalAction>  $actions */
    protected static function controls(Collection $actions): string
    {
        return $actions->map(fn (TerminalAction $action, int $index): string => Html::block(
            '<span class="bg-yellow-600 text-black font-bold px-1">'.($index + 1).'</span>'
                .'<span class="ml-1">'.Html::escape($action->label()).'</span>'
                .($action->url() === null ? '' : '<span class="ml-1 text-gray">'.Html::escape($action->url()).'</span>'),
            'flex',
        ))->implode('');
    }

    /** @return array<int, int> */
    protected static function widths(Table $table): array
    {
        $rows = $table->rows()->prepend($table->headers());

        return array_map(
            fn (int $column): int => (int) $rows->map(fn (array $row): int => mb_strwidth($row[$column]))->max(),
            array_keys($table->headers()),
        );
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<int, int>  $widths
     */
    protected static function row(array $row, array $widths): string
    {
        return rtrim(collect($row)
            ->map(fn (string $cell, int $column): string => Html::pad($cell, $widths[$column]))
            ->implode('   '));
    }

    protected static function text(string $text, int $width, string $classes = ''): string
    {
        return Html::lines($text, $width)
            ->map(fn (string $line): string => Html::block(Html::escape($line), $classes))
            ->implode('');
    }
}
