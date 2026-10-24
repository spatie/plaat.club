<?php

declare(strict_types=1);

namespace Laravel\Converse\Runs;

use Closure;
use Laravel\Converse\Contracts\RunEvents;
use Laravel\Converse\Thread;

class RunWriters
{
    /** @var array<string, RunEvents> */
    protected array $writers = [];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function writingTo(Thread $thread, RunEvents $events, Closure $callback): mixed
    {
        $key = $this->key($thread);

        $this->writers[$key] = $events;

        try {
            return $callback();
        } finally {
            unset($this->writers[$key]);
        }
    }

    public function for(Thread $thread): ?RunEvents
    {
        return $this->writers[$this->key($thread)] ?? null;
    }

    protected function key(Thread $thread): string
    {
        return $thread->bot().'|'.$thread->encode();
    }
}
