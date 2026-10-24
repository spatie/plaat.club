<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Message;

interface HandlesSubscribedMessages
{
    public function handleSubscribedMessage(Message $message): void;
}
