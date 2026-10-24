<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Telegram;

use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\Divider;
use Laravel\Converse\Cards\Field;
use Laravel\Converse\Cards\Fields;
use Laravel\Converse\Cards\Image;
use Laravel\Converse\Cards\Table;
use Laravel\Converse\Cards\Text;
use Laravel\Converse\Exceptions\InvalidCardException;

class RichMessageRenderer extends KeyboardRenderer
{
    /** @see https://core.telegram.org/bots/api#rich-message-limits */
    protected const int MAX_LENGTH = 32768;

    /** @see https://core.telegram.org/bots/api#rich-message-limits */
    protected const int MAX_BLOCKS = 500;

    /** @return array<string, mixed> */
    protected function payload(Card $card): array
    {
        $html = $this->html($card);

        if (static::length($html) > static::MAX_LENGTH) {
            throw new InvalidCardException('Telegram card text exceeds the '.static::MAX_LENGTH.' character limit.');
        }

        if (preg_match_all('/<(?:p|pre|h1|footer|hr|figure|table)\b/', $html) > static::MAX_BLOCKS) {
            throw new InvalidCardException('Telegram card exceeds the '.static::MAX_BLOCKS.' block limit.');
        }

        return ['rich_message' => ['html' => $html], ...$this->keyboard()];
    }

    protected function separator(): string
    {
        return '';
    }

    protected function heading(string $title): string
    {
        return '<h1>'.static::escape($title).'</h1>';
    }

    protected function caption(string $subtitle): string
    {
        return '<footer>'.static::escape($subtitle).'</footer>';
    }

    public function renderText(Text $text): string
    {
        return '<p>'.static::format($text->text()).'</p>';
    }

    public function renderImage(Image $image): string
    {
        return '<figure><img src="'.static::escape($image->url()).'"/>'.($image->alt() === null ? '' : '<figcaption>'.static::escape($image->alt()).'</figcaption>').'</figure>';
    }

    public function renderDivider(Divider $divider): string
    {
        return '<hr/>';
    }

    public function renderFields(Fields $fields): string
    {
        return '<table bordered>'.$fields->children()->map(fn (Field $field): string => '<tr><th>'.static::escape($field->label()).'</th><td>'.static::format($field->value()).'</td></tr>')->implode('').'</table>';
    }

    public function renderTable(Table $table): string
    {
        return '<table bordered>'.$table->rows()
            ->prepend($table->headers())
            ->map(fn (array $row, int $index): string => '<tr>'.implode('', array_map(fn (string $cell): string => ($index === 0 ? '<th>' : '<td>').static::escape($cell).($index === 0 ? '</th>' : '</td>'), $row)).'</tr>')
            ->implode('').'</table>';
    }
}
