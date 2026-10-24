<?php

declare(strict_types=1);

namespace Laravel\Converse\Storage;

use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\ConverseManager;
use Laravel\Converse\Enums\RunStatus;
use Laravel\Converse\Enums\ThreadType;
use Laravel\Converse\Exceptions\ConverseException;
use Laravel\Converse\Exceptions\RunNoLongerLiveException;
use Laravel\Converse\Models\Run;
use Laravel\Converse\Models\Thread as ThreadModel;
use Laravel\Converse\Models\ThreadEvent;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\RunState;
use Laravel\Converse\Storage\Concerns\InteractsWithThreadRows;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadEventRange;

class DatabaseRunStore implements RunStore
{
    use InteractsWithThreadRows;

    protected const int EVENTS_PER_READ = 200;

    public function claim(Thread $thread, string $runId, int $timeoutSeconds, ?string $messageId = null): bool
    {
        $threadRow = $this->threadRow($thread);

        $this->expireStaleRuns($threadRow->id);

        $query = $this->newRunQuery();

        try {
            $query->withSavepointIfNeeded(fn (): Run => $query->create([
                'id' => $runId,
                'thread_id' => $threadRow->id,
                'thread_lock' => ConverseManager::runModel()::threadLockFor($threadRow->id),
                'status' => RunStatus::Queued,
                'client_message_id' => $messageId,
                'expires_at' => $this->deadline($timeoutSeconds),
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function start(string $runId, int $timeoutSeconds): bool
    {
        if ($this->expireIfStale($this->find($runId))) {
            return false;
        }

        return $this->newRunQuery()
            ->whereKey($runId)
            ->whereNull('finished_at')
            ->update([
                'status' => RunStatus::Running,
                'expires_at' => $this->deadline($timeoutSeconds),
            ]) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public function append(string $runId, array $payloads): ThreadEventRange
    {
        return $this->withLiveRun($runId, fn (Run $run): ThreadEventRange => $this->insertEvents($run, $payloads));
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public function appendPrompt(string $runId, ?string $messageId, array $payloads): ThreadEventRange
    {
        return $this->withLiveRun($runId, function (Run $run) use ($messageId, $payloads): ThreadEventRange {
            $run->forceFill(['prompt_message_id' => $messageId])->save();

            return $this->insertEvents($run, $payloads);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(string $runId, array $payload): bool
    {
        try {
            return $this->withLiveRun($runId, function (Run $run) use ($payload): bool {
                $this->insertEvents($run, [$payload]);

                $this->finish($run->id, RunStatus::Cancelled);

                return true;
            });
        } catch (RunNoLongerLiveException) {
            return false;
        }
    }

    public function status(string $runId): RunStatus
    {
        $run = $this->find($runId);

        if ($this->expireIfStale($run)) {
            return RunStatus::Failed;
        }

        return $run->status;
    }

    public function finish(string $runId, RunStatus $status): void
    {
        if (! $status->isFinished()) {
            throw new InvalidArgumentException("Run status [{$status->value}] does not finish a run.");
        }

        $this->newRunQuery()->whereKey($runId)->whereNull('finished_at')->update($this->finishedAttributes($runId, $status));
    }

    public function liveRun(Thread $thread): ?RunState
    {
        return $this->stateForThread($thread, function (int $threadId): ?Run {
            $this->expireStaleRuns($threadId);

            return $this->newRunQuery()->where('thread_lock', ConverseManager::runModel()::threadLockFor($threadId))->first();
        });
    }

    public function hasAnswered(Thread $thread, string $messageId): bool
    {
        return $this->newRunQuery()
            ->whereIn('thread_id', $this->threadQuery($thread)->select('id'))
            ->where('client_message_id', $messageId)
            ->where('status', RunStatus::Completed)
            ->exists();
    }

    public function findRun(Thread $thread, string $runId): ?RunState
    {
        return $this->stateForThread($thread, fn (int $threadId): ?Run => $this->newRunQuery()->whereKey($runId)->where('thread_id', $threadId)->first());
    }

    public function lastInterruptedRun(Thread $thread): ?RunState
    {
        return $this->stateForThread($thread, fn (int $threadId): ?Run => $this->newRunQuery()
            ->where('thread_id', $threadId)
            ->whereIn('status', [RunStatus::Cancelled, RunStatus::Failed])
            ->whereHas('events')
            ->orderByDesc('id')
            ->first());
    }

    public function threadWithLatestRun(Thread $channel): ?Thread
    {
        $run = $this->newRunQuery()
            ->with('thread')
            ->whereHas('thread', fn (Builder $query): Builder => $query
                ->where('bot', $channel->bot() ?? '')
                ->where('connection', $channel->connection())
                ->where('channel', $channel->channel()->id())
                ->where(fn (Builder $query): Builder => $query->whereNotNull('agent_conversation_id')->orHas('messages')))
            ->orderByDesc('id')
            ->first();

        return $run instanceof Run ? $channel->withId(Thread::fromExternalId($channel->connection(), $run->thread->external_id)->id()) : null;
    }

    public function lastEventSequence(Thread $thread): int
    {
        return (int) $this->threadQuery($thread)->value('last_event_sequence');
    }

    public function firstAvailableSequence(Thread $thread): ?int
    {
        $sequence = $this->threadEventQuery($thread)->min('sequence');

        return $sequence === null ? null : (int) $sequence;
    }

    /**
     * @return array<int, array{sequence: int, payload: array<mixed>}>
     */
    public function readThread(Thread $thread, int $after): array
    {
        return $this->eventsAfter($this->threadEventQuery($thread), $after);
    }

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}|null, mixed, array{offset: int, live: bool}>
     */
    public function followThread(Thread $thread, int $after, int $intervalMilliseconds, int $idleMilliseconds, int $heartbeatMilliseconds = 0): Generator
    {
        $threadId = $this->threadQuery($thread)->value('id');

        if ($threadId === null) {
            return ['offset' => $after, 'live' => false];
        }

        $silentSince = Date::now()->getPreciseTimestamp(3);
        $lastYieldAt = $silentSince;

        while (true) {
            $this->expireStaleRuns((int) $threadId);

            $live = $this->hasLiveRun((int) $threadId);
            $events = $this->eventsAfter($this->newThreadEventQuery()->where('thread_id', $threadId), $after);

            yield from $events;

            $now = Date::now()->getPreciseTimestamp(3);

            if ($events !== []) {
                $after = $events[array_key_last($events)]['sequence'];
                $silentSince = $now;
                $lastYieldAt = $now;

                continue;
            }

            if (! $live || $now - $silentSince >= $idleMilliseconds) {
                return ['offset' => $after, 'live' => $live];
            }

            if ($heartbeatMilliseconds > 0 && $now - $lastYieldAt >= $heartbeatMilliseconds) {
                $lastYieldAt = $now;

                yield null;
            }

            Sleep::for($intervalMilliseconds)->milliseconds();
        }
    }

    /**
     * @param  Builder<ThreadEvent>  $events
     * @return array<int, array{sequence: int, payload: array<mixed>}>
     */
    protected function eventsAfter(Builder $events, int $after): array
    {
        return $events
            ->where('sequence', '>', $after)
            ->orderBy('sequence')
            ->limit(static::EVENTS_PER_READ)
            ->get()
            ->map(fn (ThreadEvent $event): array => ['sequence' => $event->sequence, 'payload' => $event->payload])
            ->all();
    }

    protected function hasLiveRun(int $threadId): bool
    {
        return $this->newRunQuery()->where('thread_lock', ConverseManager::runModel()::threadLockFor($threadId))->exists();
    }

    /**
     * @template TResult
     *
     * @param  Closure(Run): TResult  $callback
     * @return TResult
     *
     * @throws RunNoLongerLiveException
     */
    protected function withLiveRun(string $runId, Closure $callback): mixed
    {
        return $this->newRunQuery()->getConnection()->transaction(function () use ($runId, $callback): mixed {
            $this->newRunQuery()->whereKey($runId)->touch();

            $run = $this->newRunQuery()->lockForUpdate()->find($runId) ?? throw new ConverseException("Run [{$runId}] does not exist.");

            $this->assertLive($run);

            return $callback($run);
        });
    }

    protected function assertLive(Run $run): void
    {
        if ($run->status->isFinished()) {
            throw RunNoLongerLiveException::finished($run->id);
        }

        if ($run->isStale()) {
            throw RunNoLongerLiveException::expired($run->id);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function insertEvents(Run $run, array $payloads): ThreadEventRange
    {
        $threadRow = ConverseManager::threadModel()::query()->lockForUpdate()->findOrFail($run->thread_id);
        $previous = $threadRow->last_event_sequence;
        $sequence = $previous;
        $now = Date::now();
        $rows = [];

        foreach ($payloads as $payload) {
            $rows[] = [
                'thread_id' => $threadRow->id,
                'run_id' => $run->id,
                'sequence' => ++$sequence,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ];
        }

        $this->newThreadEventQuery()->insert($rows);

        $threadRow->forceFill(['last_event_sequence' => $sequence])->save();

        return new ThreadEventRange($previous, $sequence);
    }

    /**
     * @return Builder<Run>
     */
    protected function newRunQuery(): Builder
    {
        return ConverseManager::runModel()::query();
    }

    /**
     * @return Builder<ThreadEvent>
     */
    protected function newThreadEventQuery(): Builder
    {
        return ConverseManager::threadEventModel()::query();
    }

    /**
     * @return Builder<ThreadEvent>
     */
    protected function threadEventQuery(Thread $thread): Builder
    {
        return $this->newThreadEventQuery()->whereIn('thread_id', $this->threadQuery($thread)->select('id'));
    }

    protected function threadRow(Thread $thread): ThreadModel
    {
        return ConverseManager::threadModel()::query()->firstOrCreate(
            $this->threadAttributes($thread),
            ['channel' => $thread->channel()->id(), 'type' => ThreadType::fromDirectMessage($thread->isDirectMessage())],
        );
    }

    /**
     * @param  Closure(int): ?Run  $query
     */
    protected function stateForThread(Thread $thread, Closure $query): ?RunState
    {
        $threadRow = $this->threadQuery($thread)->first();

        if (! $threadRow instanceof ThreadModel) {
            return null;
        }

        return $this->state($threadRow, $query($threadRow->id));
    }

    protected function state(ThreadModel $threadRow, ?Run $run): ?RunState
    {
        if (! $run instanceof Run) {
            return null;
        }

        return new RunState($run->id, $this->replayOffset($threadRow, $run), $run->prompt_message_id);
    }

    protected function replayOffset(ThreadModel $threadRow, Run $run): int
    {
        $firstSequence = $this->newThreadEventQuery()->where('run_id', $run->id)->min('sequence');

        return $firstSequence === null ? $threadRow->last_event_sequence : (int) $firstSequence - 1;
    }

    protected function find(string $runId): Run
    {
        return $this->newRunQuery()->find($runId) ?? throw new ConverseException("Run [{$runId}] does not exist.");
    }

    protected function expireStaleRuns(int $threadId): void
    {
        $this->newRunQuery()->stale()->where('thread_id', $threadId)->get()->each(function (Run $run): void {
            $this->expireIfStale($run);
        });
    }

    protected function expireIfStale(Run $run): bool
    {
        if (! $run->isStale()) {
            return false;
        }

        return $this->newRunQuery()->getConnection()->transaction(function () use ($run): bool {
            $this->newRunQuery()->whereKey($run->id)->touch();

            $stale = $this->newRunQuery()->stale()->lockForUpdate()->find($run->id);

            if (! $stale instanceof Run) {
                return false;
            }

            $this->failExpired($stale);

            return true;
        });
    }

    protected function failExpired(Run $run): void
    {
        $this->insertEvents($run, [TurnEvents::error()]);

        $this->finish($run->id, RunStatus::Failed);
    }

    /**
     * @return array<string, mixed>
     */
    protected function finishedAttributes(string $runId, RunStatus $status): array
    {
        return [
            'status' => $status,
            'thread_lock' => ConverseManager::runModel()::releasedLockFor($runId),
            'finished_at' => Date::now(),
        ];
    }

    protected function deadline(int $seconds): ?DateTimeInterface
    {
        return $seconds > 0 ? Date::now()->addSeconds($seconds) : null;
    }
}
