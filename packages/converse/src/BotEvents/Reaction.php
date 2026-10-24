<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents;

use Laravel\Converse\BotEvents\Concerns\InteractsWithEvent;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class Reaction implements BotEvent
{
    use InteractsWithEvent;

    /** @param  array<string, mixed>  $raw */
    public function __construct(
        protected Thread $thread,
        protected string $emoji,
        protected User $user,
        protected ?string $messageId = null,
        protected bool $added = true,
        protected ?string $eventId = null,
        protected array $raw = [],
        protected bool $isDirectMessage = false,
    ) {}

    public function emoji(): string
    {
        return $this->emoji;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function isAdded(): bool
    {
        return $this->added;
    }

    public function is(Emoji|string $emoji): bool
    {
        return Emoji::unicode($this->emoji) === Emoji::unicode($emoji);
    }
}
