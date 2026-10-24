<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\Thread;
use Laravel\Converse\User;

interface BotEvent
{
    public function thread(): Thread;

    public function user(): User;

    public function withThread(Thread $thread): static;

    public function eventId(): ?string;

    public function isDirectMessage(): bool;
}
