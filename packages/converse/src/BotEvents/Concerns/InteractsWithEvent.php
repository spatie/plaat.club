<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents\Concerns;

use Laravel\Converse\Thread;
use Laravel\Converse\User;

trait InteractsWithEvent
{
    public function thread(): Thread
    {
        return $this->thread;
    }

    public function withThread(Thread $thread): static
    {
        $event = clone $this;
        $event->thread = $thread;

        return $event;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function eventId(): ?string
    {
        return $this->eventId;
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function isDirectMessage(): bool
    {
        return $this->isDirectMessage;
    }
}
