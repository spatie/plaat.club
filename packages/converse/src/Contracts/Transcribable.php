<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\Bot;
use Laravel\Converse\Thread;

interface Transcribable
{
    public function transcribe(ConversationStore $conversations, Bot $bot, Thread $thread): void;
}
