<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Message;

interface HandlesDirectMessages
{
    public function handleDirectMessage(Message $message): void;
}
