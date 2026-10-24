<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Message;

interface HandlesMessages
{
    public function handleMessage(Message $message): void;
}
