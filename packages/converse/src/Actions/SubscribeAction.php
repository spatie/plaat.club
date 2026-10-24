<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Thread;

class SubscribeAction extends Action
{
    public function __construct(protected ConversationStore $conversations) {}

    public function handle(Thread $thread): void
    {
        $this->conversations->subscribe($thread);
    }
}
