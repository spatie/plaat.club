<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Message;

interface HandlesMentions
{
    public function handleMention(Message $message): void;
}
