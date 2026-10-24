<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Converse\Enums\TerminalDisplay;
use Stringable;

class Screen
{
    protected const int RESULT_LINES = 8;

    protected const int ARGUMENT_WIDTH = 40;

    public static function header(string $bot, string $meta, string $hint, int $width): string
    {
        return static::turn(
            $bot,
            'text-cyan',
            Html::block(Html::escape($meta), 'text-gray').Html::block(Html::escape($hint), 'text-gray'),
            $width,
        );
    }

    public static function turn(string $label, string $classes, string $body, int $width): string
    {
        return Html::gap().'<div class="flex mx-2 w-'.$width.'">'
            .'<span class="font-bold '.$classes.'">'.Html::escape($label).'</span>'
            .Html::rule()
            .'</div>'
            .$body;
    }

    public static function status(string $label): string
    {
        return Html::block(
            '<span class="text-yellow">⋮</span><span class="ml-1 text-gray">'.Html::escape($label).'</span>',
            'flex',
        );
    }

    /** @param  array<array-key, mixed>  $arguments */
    public static function tool(string $name, array $arguments, mixed $outcome, float $milliseconds, bool $failed, TerminalDisplay $display, int $width): string
    {
        if ($display === TerminalDisplay::Hidden) {
            return '';
        }

        $call = Html::lines($name.'('.collect($arguments)
            ->map(fn (mixed $value, int|string $key): string => $key.': '.Str::limit(static::json($value), static::ARGUMENT_WIDTH, '…'))
            ->implode(', ').')', $width - 12);

        $lines = $display === TerminalDisplay::Full ? static::lines($outcome) : collect();

        return $call->map(fn (string $line, int $index): string => Html::block(
            ($index === 0 ? '<span class="'.($failed ? 'text-red' : 'text-green').'">⏺</span>' : '<span>&nbsp;</span>')
            .'<span class="ml-1 font-bold">'.Html::escape($line).'</span>'
            .($index === $call->count() - 1 ? '<span class="ml-1 text-gray">'.static::duration($milliseconds / 1000).'</span>' : ''),
            'flex',
        ))->implode('').$lines->take(static::RESULT_LINES)->map(fn (string $line, int $index): string => Html::block(
            '<span class="ml-2 text-gray">'.($index === 0 ? '⎿' : ' ').'</span>'
            .'<span class="ml-1 '.($failed ? 'text-red' : 'text-gray').'">'.Html::escape(Str::limit($line, $width - 4, '…')).'</span>',
            'flex',
        ))->implode('').($lines->count() > static::RESULT_LINES
            ? Html::block('<span class="ml-4 text-gray">… +'.($hidden = $lines->count() - static::RESULT_LINES).' '.Str::plural('line', $hidden).'</span>')
            : '');
    }

    public static function reasoning(string $reasoning, TerminalDisplay $display, int $width): string
    {
        $reasoning = Str::trim($reasoning);

        if ($display === TerminalDisplay::Hidden || $reasoning === '') {
            return '';
        }

        $words = Str::wordCount($reasoning);

        return Html::block(
            '<span class="text-magenta">∴</span><span class="ml-1 text-gray">Thought · '.$words.' '.Str::plural('word', $words).'</span>',
            'flex',
        ).($display === TerminalDisplay::Full
            ? Html::lines($reasoning, $width - 2)->map(fn (string $line): string => Html::block('<span class="ml-2 text-gray italic">'.Html::escape($line).'</span>'))->implode('')
            : '');
    }

    public static function stats(int $tokens, float $seconds, ?int $context = null, ?int $window = null): string
    {
        $usage = $context !== null && $window !== null ? ' · '.(int) round($context / $window * 100).'% of context' : '';

        return Html::block(Html::escape("{$tokens} tokens · ".static::duration($seconds).$usage), 'text-gray');
    }

    /** @return Collection<int, string> */
    protected static function lines(mixed $outcome): Collection
    {
        $text = is_string($outcome) || $outcome instanceof Stringable
            ? static::pretty((string) $outcome)
            : static::json($outcome instanceof Arrayable ? $outcome->toArray() : $outcome, JSON_PRETTY_PRINT);

        $text = Str::trim(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '  '], $text));

        return collect(explode("\n", $text === '' ? '(empty)' : $text));
    }

    protected static function pretty(string $text): string
    {
        $decoded = json_decode($text);

        return is_array($decoded) || is_object($decoded) ? static::json($decoded, JSON_PRETTY_PRINT) : $text;
    }

    protected static function json(mixed $value, int $flags = 0): string
    {
        $json = (string) json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return (string) preg_replace_callback('/^(?: {4})+/m', fn (array $indent): string => str_repeat(' ', intdiv(strlen($indent[0]), 2)), $json);
    }

    protected static function duration(float $seconds): string
    {
        return $seconds < 1 ? (int) round($seconds * 1000).'ms' : number_format($seconds, 1).'s';
    }
}
