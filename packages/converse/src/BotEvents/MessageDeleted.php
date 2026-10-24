<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents;

use DateTimeImmutable;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Concerns\InteractsWithEvent;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Transcribable;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class MessageDeleted implements BotEvent, Transcribable
{
    use InteractsWithEvent;

    /** @param  array<string, mixed>  $raw */
    public function __construct(
        protected Thread $thread,
        protected string $messageId,
        protected User $user,
        protected ?DateTimeImmutable $deletedAt = null,
        protected ?string $eventId = null,
        protected array $raw = [],
        protected bool $isDirectMessage = false,
    ) {}

    /** @internal */
    public function transcribe(ConversationStore $conversations, Bot $bot, Thread $thread): void
    {
        $conversations->deleteUserMessage($this);
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
