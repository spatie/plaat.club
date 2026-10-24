<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Telegram;

use Closure;
use Laravel\Converse\Drivers\PlaceholderTokens;

class MarkdownRenderer
{
    protected const string CODE_BLOCK = '/```([^\n`]*)\n(.*?)```/s';

    protected const string CODE = '/`([^`\n]+)`/';

    protected const string LINK = '/\[([^\]]+)]\(([^)]+)\)/';

    protected const string ASTERISK_ITALIC = '/(?<!\*)\*([^*\n]+)\*(?!\*)/';

    protected const string UNDERSCORE_ITALIC = '/(?<!\w)_([^_\n]+)_(?!\w)/';

    protected const string RESERVED = '/([_*\[\]()~`>#+\-=|{}.!\\\\])/';

    public static function render(string $markdown): string
    {
        return static::inline(PlaceholderTokens::strip($markdown));
    }

    protected static function inline(string $markdown): string
    {
        return PlaceholderTokens::protect($markdown, [
            static::CODE_BLOCK => fn (array $match): string => '```'.$match[1]."\n".static::escapeCode($match[2]).'```',
            static::CODE => fn (array $match): string => '`'.static::escapeCode($match[1]).'`',
            static::LINK => fn (array $match): string => '['.static::inline($match[1]).']('.static::escapeUrl($match[2]).')',
            PlaceholderTokens::BOLD => static::wrap('*'),
            PlaceholderTokens::STRIKE => static::wrap('~'),
            static::ASTERISK_ITALIC => static::wrap('_'),
            static::UNDERSCORE_ITALIC => static::wrap('_'),
        ], static::escape(...));
    }

    /**
     * @return Closure(array<int|string, string>): string
     */
    protected static function wrap(string $marker): Closure
    {
        return fn (array $match): string => $marker.static::inline($match[1]).$marker;
    }

    protected static function escape(string $text): string
    {
        return preg_replace(static::RESERVED, '\\\\$1', $text) ?? $text;
    }

    protected static function escapeCode(string $text): string
    {
        return preg_replace('/([`\\\\])/', '\\\\$1', $text) ?? $text;
    }

    protected static function escapeUrl(string $url): string
    {
        return preg_replace('/([)\\\\])/', '\\\\$1', $url) ?? $url;
    }
}
