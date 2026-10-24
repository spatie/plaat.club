<?php

declare(strict_types=1);

namespace Laravel\Converse\Runs;

use Illuminate\Support\Facades\Date;
use Laravel\Converse\Contracts\RunEvents;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Exceptions\RunNoLongerLiveException;

class RunEventWriter implements RunEvents
{
    /**
     * @var array<int, string>
     */
    protected const array BATCHED_EVENTS = ['TEXT_MESSAGE_CONTENT', 'REASONING_MESSAGE_CONTENT', 'TOOL_CALL_ARGS'];

    protected const int MAX_BATCH_EVENTS = 32;

    protected const int MAX_BATCH_BYTES = 32768;

    /** @var list<array<string, mixed>> */
    protected array $batch = [];

    protected int $batchBytes = 0;

    protected float $batchStartedAt = 0;

    protected bool $ended = false;

    public function __construct(
        protected RunStore $runs,
        protected string $runId,
        protected int $idleTimeoutSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function write(array $event): void
    {
        $type = $event['type'] ?? null;

        if (in_array($type, ['RUN_FINISHED', 'RUN_ERROR'])) {
            $this->ended = true;
        }

        if ($this->batch === []) {
            $this->batchStartedAt = Date::now()->getPreciseTimestamp(3);
        }

        $this->batch[] = $event;
        $this->batchBytes += is_string($event['delta'] ?? null) ? strlen($event['delta']) : 0;

        if (! in_array($type, static::BATCHED_EVENTS) || $this->batchIsFull()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->batch === []) {
            return;
        }

        $events = $this->batch;

        $this->batch = [];
        $this->batchBytes = 0;

        $this->runs->append($this->runId, $events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function fail(array $events): void
    {
        $events = $this->ended ? [] : $events;

        try {
            foreach ($events as $event) {
                $this->write($event);
            }

            $this->flush();
        } catch (RunNoLongerLiveException) {
            //
        }
    }

    protected function batchIsFull(): bool
    {
        return count($this->batch) >= static::MAX_BATCH_EVENTS
            || $this->batchBytes >= static::MAX_BATCH_BYTES
            || $this->batchReachedAgeLimitOnWrite();
    }

    protected function batchReachedAgeLimitOnWrite(): bool
    {
        $batchAgeInSeconds = (Date::now()->getPreciseTimestamp(3) - $this->batchStartedAt) / 1000;

        return $this->idleTimeoutSeconds > 0 && $batchAgeInSeconds >= $this->idleTimeoutSeconds / 4;
    }
}
