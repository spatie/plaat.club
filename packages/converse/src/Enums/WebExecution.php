<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum WebExecution: string
{
    case Queued = 'queued';

    case AfterResponse = 'after_response';

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $connection = config('converse.queue.connection') ?? config('queue.default');

        return (bool) ($config['queue'] ?? true) && config("queue.connections.{$connection}.driver") !== 'sync'
            ? self::Queued
            : self::AfterResponse;
    }
}
