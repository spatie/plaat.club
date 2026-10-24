<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Drivers\SupportsDeleteMessages;
use Laravel\Converse\Thread;

class DeleteMessageAction extends Action
{
    public function __construct(protected ConversationStore $conversations) {}

    public function handle(Thread $thread, string $messageId): void
    {
        $this->driverFor($thread, SupportsDeleteMessages::class)->deleteMessage($thread, $messageId);

        $this->conversations->deleteBotMessage($thread, $messageId);
    }
}
