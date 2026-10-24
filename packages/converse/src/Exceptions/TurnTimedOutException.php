<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

use Throwable;

class TurnTimedOutException extends ConverseException
{
    public static function forWebTurn(string $bot, string $runId, int $seconds, ?Throwable $previous = null): static
    {
        return new static("The [{$bot}] bot's web turn [{$runId}] exceeded its [{$seconds}] second run timeout and was stopped. Raise [converse.connections.web.run_timeout] (CONVERSE_WEB_RUN_TIMEOUT), and keep the queue connection's [retry_after] above it.", previous: $previous);
    }
}
