<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

use Laravel\Converse\Enums\Capability;

class UnsupportedCapabilityException extends ConverseException
{
    /**
     * @param  Capability|class-string  ...$capabilities
     */
    public static function for(string $connection, Capability|string ...$capabilities): static
    {
        return new static(sprintf(
            'The [%s] connection does not support %s.',
            $connection,
            collect($capabilities)
                ->map(fn (Capability|string $capability): string => $capability instanceof Capability ? $capability->label() : "[{$capability}]")
                ->implode(' or '),
        ));
    }
}
