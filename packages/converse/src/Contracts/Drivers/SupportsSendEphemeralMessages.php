<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Cards\Card;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

interface SupportsSendEphemeralMessages extends Driver
{
    public function sendEphemeralMessage(Thread $thread, Card|Markdown|string $content, User $to): SentMessage;
}
