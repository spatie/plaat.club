<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents;

use Laravel\Converse\Bot;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Transcribable;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class MessageUpdated implements BotEvent, Transcribable
{
    public function __construct(protected Message $message) {}

    /** @internal */
    public function transcribe(ConversationStore $conversations, Bot $bot, Thread $thread): void
    {
        if (! $this->message->user()->isSelf() && $bot->listensTo($this->message, $thread)) {
            $conversations->storeUserMessage($this);

            return;
        }

        $conversations->updateUserMessage($this);
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function thread(): Thread
    {
        return $this->message->thread();
    }

    public function withThread(Thread $thread): static
    {
        return $this->withMessage($this->message->withThread($thread));
    }

    public function withMessage(Message $message): static
    {
        $event = clone $this;
        $event->message = $message;

        return $event;
    }

    public function user(): User
    {
        return $this->message->user();
    }

    public function isDirectMessage(): bool
    {
        return $this->message->isDirectMessage();
    }

    public function eventId(): ?string
    {
        return $this->message->eventId();
    }
}
