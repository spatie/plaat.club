<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Cards\Card;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

class SendMessageAction extends Action
{
    public function handle(Thread $thread, Card|Markdown|string $content, ?Thread $to = null): SentMessage
    {
        $driver = $thread->driver();
        $target = $to ?? $thread;

        return $this->deliver($thread, fn (): SentMessage => $driver->sendMessage($target, $content), $this->textFor($content));
    }
}
