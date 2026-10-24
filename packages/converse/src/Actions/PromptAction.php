<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\AgentBot;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Thread;

class PromptAction
{
    public function __construct(protected BotRegistrar $registrar) {}

    public function handle(Thread $thread, string $prompt, ?string $key = null): void
    {
        $bot = $thread->bot() ?? throw new InvalidBotException('Unable to prompt a thread that is not bound to a bot.');

        if (! $this->registrar->get($bot) instanceof AgentBot) {
            throw new InvalidBotException("Bot [{$bot}] has no agent, so it cannot be prompted. Define the bot with [Converse::agent()].");
        }

        $this->registrar->incoming($bot, Message::scheduled($thread, $prompt, $key === null ? null : "schedule:{$bot}:{$key}"));
    }
}
