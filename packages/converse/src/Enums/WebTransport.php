<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

use Laravel\Converse\Exceptions\ConverseException;

enum WebTransport: string
{
    case Stream = 'stream';

    case Poll = 'poll';

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $transport = (string) ($config['transport'] ?? self::Stream->value);
        $connection = (string) ($config['name'] ?? '');

        return self::tryFrom($transport)
            ?? throw new ConverseException("Web connection [{$connection}] has an unsupported transport [{$transport}]. Use [stream] or [poll].");
    }
}
