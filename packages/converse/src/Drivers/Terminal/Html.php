<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

use Illuminate\Support\Collection;
use Symfony\Component\Console\Formatter\OutputFormatter;

class Html
{
    public static function escape(string $text): string
    {
        return (string) preg_replace_callback(
            '/^ +| {2,}/',
            fn (array $matches): string => str_repeat('&nbsp;', mb_strlen($matches[0])),
            htmlspecialchars(OutputFormatter::escape($text), ENT_QUOTES | ENT_SUBSTITUTE),
        );
    }

    /** @return Collection<int, string> */
    public static function lines(string $text, int $width): Collection
    {
        return collect(explode("\n", $text))->flatMap(
            fn (string $line): array => explode("\n", wordwrap($line, max($width, 1), "\n")),
        );
    }

    public static function block(string $html, string $classes = ''): string
    {
        return '<div class="mx-2 '.$classes.'">'.($html === '' ? ' ' : $html).'</div>';
    }

    public static function gap(): string
    {
        return '<div> </div>';
    }

    public static function rule(string $color = 'gray'): string
    {
        return '<span class="flex-1 ml-1 content-repeat-─ text-'.$color.'"></span>';
    }

    public static function pad(string $text, int $width): string
    {
        return $text.str_repeat("\u{a0}", max($width - mb_strwidth($text), 0));
    }
}
