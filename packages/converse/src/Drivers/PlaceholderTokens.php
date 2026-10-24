<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers;

use Closure;
use Illuminate\Support\Str;

class PlaceholderTokens
{
    public const string BOLD = '/\*\*(.+?)\*\*/s';

    public const string STRIKE = '/~~(.+?)~~/s';

    protected const string MARKER = "\x1D";

    public static function strip(string $text): string
    {
        return str_replace(static::MARKER, '', $text);
    }

    /**
     * @param  array<string, Closure(array<int|string, string>): string>  $patterns
     * @param  Closure(string): string  $transform
     */
    public static function protect(string $text, array $patterns, Closure $transform): string
    {
        $scope = Str::random(8);
        $tokens = [];

        foreach ($patterns as $pattern => $render) {
            $text = preg_replace_callback($pattern, function (array $match) use (&$tokens, $render, $scope): string {
                $key = static::MARKER.$scope.count($tokens).static::MARKER;
                $tokens[$key] = $render($match);

                return $key;
            }, $text) ?? $text;
        }

        return str_replace(array_reverse(array_keys($tokens)), array_reverse($tokens), $transform($text));
    }
}
