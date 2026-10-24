<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Github;

use Illuminate\Support\Str;
use Laravel\Converse\Cards\ActionElement;
use Laravel\Converse\Cards\ActionRenderer;
use Laravel\Converse\Cards\Actions;
use Laravel\Converse\Cards\Button;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\CardElement;
use Laravel\Converse\Cards\CardRenderer as CardRendererContract;
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
use Laravel\Converse\Drivers\PlaceholderTokens;
use Laravel\Converse\Exceptions\InvalidCardException;

/**
 * @implements CardRendererContract<string>
 * @implements ActionRenderer<string>
 */
class CardRenderer implements ActionRenderer, CardRendererContract
{
    /** @see https://github.com/orgs/community/discussions/27190 */
    protected const int MAX_BYTES = 262144;

    public static function render(Card $card): string
    {
        $card->validate();

        if (is_string($raw = $card->raw()['github']['body'] ?? null)) {
            return $raw;
        }

        $renderer = new static;

        $blocks = collect([
            $card->title() === null ? '' : '**'.static::escape($card->title()).'**',
            $card->subtitle() === null ? '' : '_'.static::escape($card->subtitle()).'_',
        ])
            ->concat($card->children()->map(fn (CardElement $element): string => $element->renderWith($renderer)))
            ->filter(fn (string $block): bool => $block !== '');

        $body = $blocks->isEmpty() ? static::escape($card->fallbackText()) : $blocks->implode("\n\n");

        if (strlen($body) > static::MAX_BYTES) {
            throw new InvalidCardException('GitHub card exceeds the '.static::MAX_BYTES.' byte limit.');
        }

        return $body;
    }

    public function renderText(Text $text): string
    {
        return $text->isRaw() ? $text->text() : static::escape($text->text());
    }

    public function renderImage(Image $image): string
    {
        return '!['.static::label($image->alt() ?? '').']('.static::destination($image->url()).')';
    }

    public function renderDivider(Divider $divider): string
    {
        return '---';
    }

    public function renderLink(Link $link): string
    {
        return static::link($link->label(), $link->url());
    }

    public function renderFields(Fields $fields): string
    {
        return $fields->children()
            ->map(fn (Field $field): string => '- **'.static::escape($field->label()).':** '.static::escape($field->value()))
            ->implode("\n");
    }

    public function renderTable(Table $table): string
    {
        $rows = collect([static::row($table->headers())])
            ->push('|'.str_repeat(' --- |', count($table->headers())))
            ->concat($table->rows()->map(static::row(...)))
            ->implode("\n");

        return $table->caption() === null ? $rows : $rows."\n\n_".static::escape($table->caption()).'_';
    }

    public function renderSection(Section $section): string
    {
        return $section->children()
            ->map(fn (CardElement $element): string => $element->renderWith($this))
            ->filter(fn (string $block): bool => $block !== '')
            ->implode("\n\n");
    }

    public function renderActions(Actions $actions): string
    {
        return $actions->children()->map(fn (ActionElement $action): string => $action->renderWith($this))->implode("\n");
    }

    public function renderButton(Button $button): string
    {
        return '- **'.static::escape($button->label()).'**';
    }

    public function renderLinkButton(LinkButton $button): string
    {
        return '- '.static::link($button->label(), $button->url());
    }

    public function renderSelect(Select $select): string
    {
        $prefix = $select->label() === null ? '' : static::escape($select->label()).': ';

        return $select->options()
            ->map(fn (Option $option): string => '- **'.$prefix.static::escape($option->label()).'**')
            ->implode("\n");
    }

    public function renderRadioSelect(RadioSelect $select): string
    {
        return $this->renderSelect($select);
    }

    /**
     * @param  array<int, string>  $cells
     */
    protected static function row(array $cells): string
    {
        return '| '.collect($cells)
            ->map(fn (string $cell): string => Str::replace(['|', "\n"], ['\|', ' '], static::escape($cell)))
            ->implode(' | ').' |';
    }

    protected static function link(string $label, string $url): string
    {
        return '['.static::label($label).']('.static::destination($url).')';
    }

    protected static function destination(string $url): string
    {
        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', "\n" => '%0A', "\r" => '%0D']);
    }

    protected static function label(string $text): string
    {
        return Str::replace(['[', ']'], ['\[', '\]'], static::escape($text));
    }

    protected static function escape(string $text): string
    {
        $verbatim = fn (array $match): string => $match[0];

        return PlaceholderTokens::protect(
            PlaceholderTokens::strip($text),
            ['/```.*?(?:```|$)/s' => $verbatim, '/`[^`\n]*`/' => $verbatim],
            fn (string $text): string => strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '@' => '@&#8203;']),
        );
    }
}
