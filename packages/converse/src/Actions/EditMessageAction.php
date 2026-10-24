<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

class EditMessageAction extends Action
{
    public function __construct(protected ConversationStore $conversations) {}

    public function handle(Thread $thread, string $messageId, Card|Markdown|string $content): SentMessage
    {
        $driver = $this->driverFor($thread, SupportsEditMessages::class);

        $edited = $this->send($thread, fn (): SentMessage => $driver->editMessage($thread, $messageId, $content));

        $this->conversations->updateBotMessage($thread, $messageId, $this->textFor($content));

        return $edited;
    }
}
