<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

class InvalidActionCallbackException extends ConverseException
{
    public static function missingSigningSecret(): static
    {
        return new static('Action callbacks require the [converse.callbacks.signing_secret] configuration value.');
    }
}
