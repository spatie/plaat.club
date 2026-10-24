<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Closure;

class ThreadContext
{
    protected ?Thread $current = null;

    protected ?string $bot = null;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function for(Thread $target, Closure $callback): mixed
    {
        $previous = $this->current;
        $previousBot = $this->bot;

        $this->current = $target;
        $this->bot = $target->bot() ?? $this->bot;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
            $this->bot = $previousBot;
        }
    }

    public function thread(): ?Thread
    {
        return $this->current;
    }

    public function bot(): ?string
    {
        return $this->bot;
    }
}
