<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Telegram;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
use Laravel\Converse\Drivers\PlaceholderTokens;
use Laravel\Converse\Enums\ButtonStyle;
use Laravel\Converse\Exceptions\InvalidCardException;
use Laravel\Converse\Routing\CallbackRegistry;

/**
 * @implements CardRenderer<string>
 * @implements ActionRenderer<array<int, array<int, array<string, string>>>>
 */
class KeyboardRenderer implements ActionRenderer, CardRenderer
{
    /** @see https://core.telegram.org/bots/api#inputrichblockbuttons */
    protected const int BUTTONS_PER_ROW = 8;

    /** @var array<int, array<int, array<string, string>>> */
    protected array $buttons = [];

    /** @return array<string, mixed> */
    public static function render(Card $card): array
    {
        $card = Container::getInstance()->make(CallbackRegistry::class)->prepare($card, 'telegram');

        return $card->raw()['telegram'] ?? new static()->payload($card);
    }

    /** @return array<string, mixed> */
    protected function payload(Card $card): array
    {
        $html = $this->html($card);

        $length = static::length($html);

        if ($length === 0 || $length > 4096) {
            throw new InvalidCardException('Telegram card text must contain between 1 and 4096 characters.');
        }

        return ['text' => $html, 'parse_mode' => 'HTML', ...$this->keyboard()];
    }

    protected function html(Card $card): string
    {
        $this->buttons = [];

        $html = $this->join(collect([
            $card->title() === null ? '' : $this->heading($card->title()),
            $card->subtitle() === null ? '' : $this->caption($card->subtitle()),
        ])->concat($card->children()->map(fn (CardElement $child): string => $child->renderWith($this))));

        return $html === '' ? static::escape($card->fallbackText()) : $html;
    }

    /** @return array{reply_markup?: array{inline_keyboard: array<int, array<int, array<string, string>>>}} */
    protected function keyboard(): array
    {
        return $this->buttons === [] ? [] : ['reply_markup' => ['inline_keyboard' => $this->buttons]];
    }

    /** @param  Collection<int, string>  $parts */
    protected function join(Collection $parts): string
    {
        return $parts->filter(fn (string $part): bool => $part !== '')->implode($this->separator());
    }

    protected function separator(): string
    {
        return "\n\n";
    }

    protected function heading(string $title): string
    {
        return '<b>'.static::escape($title).'</b>';
    }

    protected function caption(string $subtitle): string
    {
        return '<i>'.static::escape($subtitle).'</i>';
    }

    public function renderText(Text $text): string
    {
        return static::format($text->text());
    }

    public function renderImage(Image $image): string
    {
        return static::escape($image->fallbackText());
    }

    public function renderDivider(Divider $divider): string
    {
        return '———';
    }

    public function renderLink(Link $link): string
    {
        return '<a href="'.static::escape($link->url()).'">'.static::escape($link->label()).'</a>';
    }

    public function renderFields(Fields $fields): string
    {
        return $fields->children()->map(fn (Field $field): string => '<b>'.static::escape($field->label())."</b>\n".static::format($field->value()))->implode("\n\n");
    }

    public function renderTable(Table $table): string
    {
        return '<pre>'.static::escape($table->fallbackText()).'</pre>';
    }

    public function renderSection(Section $section): string
    {
        return $this->join($section->children()->map(fn (CardElement $child): string => $child->renderWith($this)));
    }

    public function renderActions(Actions $actions): string
    {
        $row = [];

        foreach ($actions->children() as $action) {
            $rows = $action->renderWith($this);

            if ($action instanceof Button || $action instanceof LinkButton) {
                $row[] = $rows[0][0];

                continue;
            }

            array_push($this->buttons, ...static::rows($row), ...$rows);

            $row = [];
        }

        array_push($this->buttons, ...static::rows($row));

        return '';
    }

    /**
     * @param  array<int, array<string, string>>  $buttons
     * @return array<int, array<int, array<string, string>>>
     */
    protected static function rows(array $buttons): array
    {
        return collect($buttons)->chunk(static::BUTTONS_PER_ROW)->map(fn (Collection $row): array => $row->values()->all())->values()->all();
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     *
     * @see https://core.telegram.org/bots/api#inlinekeyboardbutton
     * @see https://docs.slack.dev/reference/block-kit/block-elements/button-element
     */
    public function renderButton(Button $button): array
    {
        return [[[
            'text' => $button->label(),
            'callback_data' => static::callback($button->id(), $button->value()),
            ...match ($button->style()) {
                ButtonStyle::Primary => ['style' => 'success'],
                ButtonStyle::Danger => ['style' => 'danger'],
                ButtonStyle::Default => [],
            },
        ]]];
    }

    /** @return array<int, array<int, array<string, string>>> */
    public function renderLinkButton(LinkButton $button): array
    {
        return [[['text' => $button->label(), 'url' => $button->url()]]];
    }

    /** @return array<int, array<int, array<string, string>>> */
    public function renderSelect(Select $select): array
    {
        return $select->options()->map(fn (Option $option): array => [['text' => $option->label(), 'callback_data' => static::callback($select->id(), $option->value())]])->all();
    }

    /** @return array<int, array<int, array<string, string>>> */
    public function renderRadioSelect(RadioSelect $select): array
    {
        return $this->renderSelect($select);
    }

    protected static function callback(string $id, ?string $value): string
    {
        if (str_contains($id, '|')) {
            throw new InvalidCardException("Telegram card action [{$id}] may not contain [|].");
        }

        $callback = $id.($value === null ? '' : '|'.$value);

        if (strlen($callback) > 64) {
            throw new InvalidCardException("Telegram card action [{$id}] exceeds the 64 byte callback data limit.");
        }

        return $callback;
    }

    protected static function format(string $text): string
    {
        return PlaceholderTokens::protect(
            PlaceholderTokens::strip($text),
            ['/`([^`\n]*)`/' => fn (array $match): string => '<code>'.static::escape($match[1]).'</code>'],
            fn (string $text): string => preg_replace(
                [PlaceholderTokens::BOLD, PlaceholderTokens::STRIKE, '/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/s'],
                ['<b>$1</b>', '<s>$1</s>', '<i>$1</i>'],
                static::escape($text),
            ) ?? static::escape($text),
        );
    }

    protected static function length(string $html): int
    {
        return Str::length(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
