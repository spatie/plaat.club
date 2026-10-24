<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

class InvalidBotException extends ConverseException
{
    public static function alreadyRegistered(string $bot): static
    {
        return new static("Bot [{$bot}] has already been registered with a different handler.");
    }

    public static function agentHandler(string $bot): static
    {
        return new static("Bot [{$bot}] may not register an agent. Use [Converse::agent()] to define an agent-driven bot.");
    }

    public static function notBound(string $bot, string $connection): static
    {
        return new static("Bot [{$bot}] is not bound to the [{$connection}] connection.");
    }
}
