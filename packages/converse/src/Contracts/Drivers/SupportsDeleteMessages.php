<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Thread;

interface SupportsDeleteMessages extends Driver
{
    public function deleteMessage(Thread $thread, string $messageId): void;
}
