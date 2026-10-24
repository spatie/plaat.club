<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Slack;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
use Laravel\Converse\Drivers\PlaceholderTokens;
use Laravel\Converse\Enums\ButtonStyle;
use Laravel\Converse\Exceptions\InvalidCardException;
use Laravel\Converse\Routing\CallbackRegistry;

/**
 * @implements CardRenderer<array<int, array<string, mixed>>>
 * @implements ActionRenderer<array<string, mixed>>
 */
class BlockKitRenderer implements ActionRenderer, CardRenderer
{
    /** @see https://docs.slack.dev/reference/block-kit/blocks/section-block */
    protected const int FIELDS_PER_SECTION = 10;

    /** @see https://docs.slack.dev/reference/block-kit/blocks */
    protected const int MAX_BLOCKS = 50;

    /** @see https://docs.slack.dev/reference/block-kit/blocks/section-block */
    protected const int MAX_FIELD_LENGTH = 2000;

    /** @see https://docs.slack.dev/reference/block-kit/blocks/table-block */
    protected const int MAX_TABLE_LENGTH = 10000;

    public const string LINK_ACTION_PREFIX = 'link_';

    protected const string MENTION = '/<[@#][A-Z0-9]+(?:\|[^<>|]*)?>/';

    /** @see https://github.github.com/gfm/#extended-email-autolink */
    protected const string EMAIL = '/(?<![\w.+\-\/:@])[\w.+-]+@[\w-]+(?:\.[\w-]+)+(?<![-_])(?![\w-])/';

    /** @return array<string, mixed> */
    public static function render(Card $card): array
    {
        $card = Container::getInstance()->make(CallbackRegistry::class)->prepare($card, 'slack');
        $fallback = static::escape($card->fallbackText());

        if (array_key_exists('slack', $card->raw())) {
            return $card->raw()['slack'] + ['text' => $fallback];
        }

        if (Str::length($card->title() ?? '') > 150 || Str::length(static::mrkdwn($card->subtitle() ?? '')) > 3000 || Str::length($fallback) > 4000) {
            throw new InvalidCardException('Slack card content exceeds a Block Kit limit.');
        }

        $blocks = [];

        if ($card->title() !== null) {
            $blocks[] = preg_match(static::MENTION, $card->title()) === 1
                ? ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*'.static::mrkdwn($card->title()).'*']]
                : ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $card->title(), 'emoji' => true]];
        }

        if ($card->subtitle() !== null) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => static::mrkdwn($card->subtitle())]]];
        }

        $blocks = [...$blocks, ...new static()->blocks($card->children())];

        if (count($blocks) > static::MAX_BLOCKS) {
            throw new InvalidCardException('Slack card exceeds the '.static::MAX_BLOCKS.' block limit.');
        }

        if ($blocks === [] && $card->fallbackText() === '') {
            throw new InvalidCardException('A Slack card must contain visible content or actions.');
        }

        return ['blocks' => $blocks, 'text' => $fallback];
    }

    /** @return array<int, array<string, mixed>> */
    public function renderText(Text $text): array
    {
        $mrkdwn = static::mrkdwn($text->text(), $text->isRaw());

        if (Str::length($mrkdwn) > 3000) {
            throw new InvalidCardException('Slack card text exceeds the 3000 character limit.');
        }

        return [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $mrkdwn]]];
    }

    /** @return array<int, array<string, mixed>> */
    public function renderImage(Image $image): array
    {
        return [['type' => 'image', 'image_url' => $image->url(), 'alt_text' => $image->alt() ?? 'Card image']];
    }

    /** @return array<int, array<string, mixed>> */
    public function renderDivider(Divider $divider): array
    {
        return [['type' => 'divider']];
    }

    /** @return array<int, array<string, mixed>> */
    public function renderLink(Link $link): array
    {
        return [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => static::link($link->url(), static::mrkdwn($link->label()))]]];
    }

    /** @return array<int, array<string, mixed>> */
    public function renderFields(Fields $fields): array
    {
        return $fields->children()
            ->chunk(static::FIELDS_PER_SECTION)
            ->map(fn (Collection $chunk): array => [
                'type' => 'section',
                'fields' => $chunk->map(fn (Field $field): array => ['type' => 'mrkdwn', 'text' => static::field($field)])->values()->all(),
            ])
            ->values()
            ->all();
    }

    protected static function field(Field $field): string
    {
        $text = '*'.static::mrkdwn($field->label())."*\n".static::mrkdwn($field->value());

        if (Str::length($text) > static::MAX_FIELD_LENGTH) {
            throw new InvalidCardException('Slack card field exceeds the '.static::MAX_FIELD_LENGTH.' character limit.');
        }

        return $text;
    }

    /** @return array<int, array<string, mixed>> */
    public function renderTable(Table $table): array
    {
        $cells = $table->rows()->prepend($table->headers());

        if ($cells->flatten()->sum(fn (string $value): int => Str::length($value)) > static::MAX_TABLE_LENGTH) {
            throw new InvalidCardException('Slack card table exceeds the '.static::MAX_TABLE_LENGTH.' character limit.');
        }

        $rows = $cells->map(fn (array $row): array => array_map(fn (string $value): array => ['type' => 'raw_text', 'text' => $value], $row))->all();

        return [
            ...($table->caption() === null ? [] : [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*'.static::mrkdwn($table->caption()).'*']]]),
            ['type' => 'table', 'rows' => $rows],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function renderSection(Section $section): array
    {
        return $this->blocks($section->children());
    }

    /** @return array<int, array<string, mixed>> */
    public function renderActions(Actions $actions): array
    {
        return [['type' => 'actions', 'elements' => $actions->children()->map(fn (ActionElement $action): array => $action->renderWith($this))->all()]];
    }

    /** @return array<string, mixed> */
    public function renderButton(Button $button): array
    {
        return [
            'type' => 'button',
            'action_id' => $button->id(),
            'text' => ['type' => 'plain_text', 'text' => $button->label(), 'emoji' => true],
            ...($button->value() === null ? [] : ['value' => $button->value()]),
            ...($button->style() === ButtonStyle::Default ? [] : ['style' => $button->style()->value]),
        ];
    }

    /** @return array<string, mixed> */
    public function renderLinkButton(LinkButton $button): array
    {
        return ['type' => 'button', 'action_id' => static::LINK_ACTION_PREFIX.substr(hash('sha256', $button->url()), 0, 24), 'text' => ['type' => 'plain_text', 'text' => $button->label(), 'emoji' => true], 'url' => $button->url()];
    }

    /** @return array<string, mixed> */
    public function renderSelect(Select $select): array
    {
        return [
            'type' => 'static_select',
            'action_id' => $select->id(),
            ...($select->placeholder() === null ? [] : ['placeholder' => ['type' => 'plain_text', 'text' => $select->placeholder(), 'emoji' => true]]),
            'options' => $this->options($select),
        ];
    }

    /** @return array<string, mixed> */
    public function renderRadioSelect(RadioSelect $select): array
    {
        return ['type' => 'radio_buttons', 'action_id' => $select->id(), 'options' => $this->options($select)];
    }

    /** @return array<int, array<string, mixed>> */
    protected function options(Select $select): array
    {
        return $select->options()->map(fn (Option $option): array => [
            'text' => ['type' => 'plain_text', 'text' => $option->label(), 'emoji' => true],
            'value' => $option->value(),
            ...($option->description() === null ? [] : ['description' => ['type' => 'plain_text', 'text' => $option->description(), 'emoji' => true]]),
        ])->all();
    }

    /**
     * @param  Collection<int, CardElement>  $elements
     * @return array<int, array<string, mixed>>
     */
    protected function blocks(Collection $elements): array
    {
        return $elements->flatMap(fn (CardElement $element): array => $element->renderWith($this))->values()->all();
    }

    protected static function link(string $url, string $label): string
    {
        return '<'.strtr($url, ['<' => '%3C', '>' => '%3E', '|' => '%7C']).'|'.strtr($label, ['<' => '&lt;', '>' => '&gt;']).'>';
    }

    public static function escape(string $text): string
    {
        return PlaceholderTokens::protect(
            PlaceholderTokens::strip($text),
            [static::MENTION => fn (array $match): string => $match[0]],
            fn (string $text): string => strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']),
        );
    }

    /** @see https://docs.slack.dev/messaging/formatting-message-text */
    public static function markdown(string $markdown): string
    {
        $verbatim = fn (array $match): string => $match[0];

        return PlaceholderTokens::protect(
            PlaceholderTokens::strip($markdown),
            [
                '/```.*?(?:```|$)/s' => $verbatim,
                '/`[^`\n]*`/' => $verbatim,
                '/\[[^\]]*\]\([^)]*\)/' => $verbatim,
                '/<(?:mailto:)?([^<>|\s:\/@]+@[^<>|\s:\/@]+)>/' => fn (array $match): string => static::emailLink($match[1]),
                '/<[^<>]*>/' => $verbatim,
            ],
            fn (string $text): string => preg_replace_callback(static::EMAIL, fn (array $match): string => static::emailLink($match[0]), $text) ?? $text,
        );
    }

    protected static function emailLink(string $email): string
    {
        return "[{$email}](mailto:{$email})";
    }

    protected static function mrkdwn(string $text, bool $raw = false): string
    {
        $verbatim = fn (array $match): string => $match[0];

        return PlaceholderTokens::protect(
            PlaceholderTokens::strip($raw ? $text : static::escape($text)),
            ['/```.*?(?:```|$)/s' => $verbatim, '/`[^`\n]*`/' => $verbatim],
            fn (string $text): string => preg_replace([PlaceholderTokens::BOLD, PlaceholderTokens::STRIKE], ['*$1*', '~$1~'], $text) ?? $text,
        );
    }
}
