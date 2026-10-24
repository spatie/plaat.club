<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

class RunNoLongerLiveException extends ConverseException
{
    public static function finished(string $runId): static
    {
        return new static("Run [{$runId}] has finished and takes no more events.");
    }

    public static function expired(string $runId): static
    {
        return new static("Run [{$runId}] ran past its timeout and takes no more events.");
    }
}
