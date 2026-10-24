<?php

declare(strict_types=1);

namespace Laravel\Converse\Jobs\Concerns;

trait RunsOnConverseQueue
{
    public int $timeout;

    protected function onConverseQueue(): void
    {
        $this->onConnection(config('converse.queue.connection'));
        $this->onQueue(config('converse.queue.name'));

        $this->timeout = (int) config('converse.processing.timeout');
    }
}
