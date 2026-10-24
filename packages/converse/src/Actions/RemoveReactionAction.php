<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Contracts\Drivers\SupportsRemoveReactions;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Thread;

class RemoveReactionAction extends Action
{
    public function handle(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->driverFor($thread, SupportsRemoveReactions::class)->removeReaction($thread, $messageId, $emoji);
    }
}
