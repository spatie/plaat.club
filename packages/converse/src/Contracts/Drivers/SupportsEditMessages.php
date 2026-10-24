<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Cards\Card;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

interface SupportsEditMessages extends Driver
{
    public function editMessage(Thread $thread, string $messageId, Card|Markdown|string $content): SentMessage;
}
