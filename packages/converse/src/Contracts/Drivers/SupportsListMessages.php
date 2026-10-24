<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Thread;

interface SupportsListMessages extends Driver
{
    public const int MAX_MESSAGES = 500;

    /**
     * @return iterable<int, Message>
     */
    public function listMessages(Thread $thread, ?int $limit = null, ?string $cursor = null): iterable;
}
