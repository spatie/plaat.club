<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents;

use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Concerns\InteractsWithEvent;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Transcribable;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class Command implements BotEvent, Transcribable
{
    use InteractsWithEvent;

    /** @param  array<string, mixed>  $raw */
    public function __construct(
        protected Thread $thread,
        protected string $name,
        protected User $user,
        protected string $text = '',
        protected ?string $eventId = null,
        protected array $raw = [],
        protected bool $isDirectMessage = false,
    ) {}

    /** @internal */
    public function transcribe(ConversationStore $conversations, Bot $bot, Thread $thread): void
    {
        $conversations->storeCommand($this);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function text(): string
    {
        return $this->text;
    }
}
