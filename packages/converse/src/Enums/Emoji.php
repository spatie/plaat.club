<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

use Illuminate\Support\Str;

enum Emoji: string
{
    case ThumbsUp = '👍';
    case ThumbsDown = '👎';
    case Heart = '❤';
    case Smile = '😊';
    case Laugh = '😂';
    case Thinking = '🤔';
    case Eyes = '👀';
    case Fire = '🔥';
    case Check = '✅';
    case Cross = '❌';
    case Question = '❓';
    case Party = '🎉';
    case Rocket = '🚀';
    case Star = '⭐';
    case Wave = '👋';
    case Clap = '👏';
    case Hundred = '💯';
    case Warning = '⚠';
    case RaisedHands = '🙌';
    case Muscle = '💪';
    case OkHand = '👌';
    case Sad = '😢';
    case Memo = '📝';
    case Gear = '⚙';
    case Wrench = '🔧';
    case Bug = '🐛';
    case Calendar = '📅';
    case Clock = '🕐';
    case Sun = '☀';
    case Rainbow = '🌈';

    /**
     * @return array<int, string>
     */
    public function shortcodes(): array
    {
        return match ($this) {
            self::ThumbsUp => ['thumbsup', '+1', 'thumbs_up'],
            self::ThumbsDown => ['thumbsdown', '-1', 'thumbs_down'],
            self::Heart => ['heart'],
            self::Smile => ['blush', 'smile'],
            self::Laugh => ['joy', 'laugh'],
            self::Thinking => ['thinking_face', 'thinking'],
            self::Eyes => ['eyes'],
            self::Fire => ['fire'],
            self::Check => ['white_check_mark', 'check'],
            self::Cross => ['x'],
            self::Question => ['question'],
            self::Party => ['tada', 'party'],
            self::Rocket => ['rocket'],
            self::Star => ['star'],
            self::Wave => ['wave'],
            self::Clap => ['clap'],
            self::Hundred => ['100', 'hundred'],
            self::Warning => ['warning'],
            self::RaisedHands => ['raised_hands'],
            self::Muscle => ['muscle'],
            self::OkHand => ['ok_hand'],
            self::Sad => ['cry', 'sad'],
            self::Memo => ['memo'],
            self::Gear => ['gear'],
            self::Wrench => ['wrench'],
            self::Bug => ['bug'],
            self::Calendar => ['date', 'calendar'],
            self::Clock => ['clock1', 'clock'],
            self::Sun => ['sunny', 'sun'],
            self::Rainbow => ['rainbow'],
        };
    }

    public static function parse(self|string $emoji): ?self
    {
        if ($emoji instanceof self) {
            return $emoji;
        }

        $emoji = self::clean($emoji);

        return self::tryFrom($emoji) ?? array_find(
            self::cases(),
            fn (self $case): bool => in_array($emoji, $case->shortcodes(), true),
        );
    }

    public static function unicode(self|string $emoji): string
    {
        return $emoji instanceof self
            ? $emoji->value
            : self::parse($emoji)->value ?? self::clean($emoji);
    }

    public static function shortcode(self|string $emoji): string
    {
        return $emoji instanceof self
            ? $emoji->shortcodes()[0]
            : self::parse($emoji)?->shortcodes()[0] ?? self::clean($emoji);
    }

    protected static function clean(string $emoji): string
    {
        return Str::of($emoji)
            ->trim()
            ->trim(':')
            ->replaceMatches('/(?:::skin-tone-\d+)+$/', '')
            ->replaceMatches('/[\x{1F3FB}-\x{1F3FF}]/u', '')
            ->replace("\u{FE0F}", '')
            ->value();
    }
}
