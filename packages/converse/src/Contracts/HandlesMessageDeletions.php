<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\MessageDeleted;

interface HandlesMessageDeletions
{
    public function handleMessageDeletion(MessageDeleted $event): void;
}
