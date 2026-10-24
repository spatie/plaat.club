<?php

declare(strict_types=1);

namespace Laravel\Converse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Converse\Concurrency\ConcurrencyManager;
use Laravel\Converse\Jobs\Concerns\RunsOnConverseQueue;
use Laravel\Converse\Thread;
use Throwable;

class ProcessPendingMessages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsOnConverseQueue;

    public function __construct(protected Thread $thread, protected int $settled = 0)
    {
        $this->onConverseQueue();
    }

    public function thread(): Thread
    {
        return $this->thread;
    }

    public function settled(): int
    {
        return $this->settled;
    }

    public function tries(): int
    {
        return max(1, (int) config('converse.processing.max_attempts'));
    }

    public function handle(ConcurrencyManager $concurrency): void
    {
        $concurrency->processPending($this->thread, $this->settled);
    }

    public function failed(?Throwable $exception): void
    {
        Container::getInstance()->make(ConcurrencyManager::class)->release($this->thread);
    }
}
