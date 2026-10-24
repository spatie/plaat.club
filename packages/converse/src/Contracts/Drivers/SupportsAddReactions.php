<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Thread;

interface SupportsAddReactions extends Driver
{
    public function addReaction(Thread $thread, string $messageId, Emoji|string $emoji): void;
}
