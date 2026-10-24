<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

class DuplicateMessageException extends ConverseException
{
    public static function forMessage(?string $messageId): static
    {
        return new static("Message [{$messageId}] was already sent.");
    }
}
