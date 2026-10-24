<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Contracts\Drivers\SupportsAddReactions;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Thread;

class AddReactionAction extends Action
{
    public function handle(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->driverFor($thread, SupportsAddReactions::class)->addReaction($thread, $messageId, $emoji);
    }
}
