<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Thread;

class UnsubscribeAction extends Action
{
    public function __construct(protected ConversationStore $conversations) {}

    public function handle(Thread $thread): void
    {
        $this->conversations->unsubscribe($thread);
    }
}
