<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\MessageUpdated;

interface HandlesMessageUpdates
{
    public function handleMessageUpdate(MessageUpdated $event): void;
}
