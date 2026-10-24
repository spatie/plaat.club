<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Generator;
use Laravel\Converse\Enums\RunStatus;
use Laravel\Converse\Exceptions\RunNoLongerLiveException;
use Laravel\Converse\RunState;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadEventRange;

interface RunStore
{
    public function claim(Thread $thread, string $runId, int $timeoutSeconds, ?string $messageId = null): bool;

    public function start(string $runId, int $timeoutSeconds): bool;

    /**
     * @param  list<array<string, mixed>>  $payloads
     *
     * @throws RunNoLongerLiveException
     */
    public function append(string $runId, array $payloads): ThreadEventRange;

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public function appendPrompt(string $runId, ?string $messageId, array $payloads): ThreadEventRange;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(string $runId, array $payload): bool;

    public function status(string $runId): RunStatus;

    public function finish(string $runId, RunStatus $status): void;

    public function liveRun(Thread $thread): ?RunState;

    public function hasAnswered(Thread $thread, string $messageId): bool;

    public function findRun(Thread $thread, string $runId): ?RunState;

    public function lastInterruptedRun(Thread $thread): ?RunState;

    public function threadWithLatestRun(Thread $channel): ?Thread;

    public function lastEventSequence(Thread $thread): int;

    public function firstAvailableSequence(Thread $thread): ?int;

    /**
     * @return array<int, array{sequence: int, payload: array<mixed>}>
     */
    public function readThread(Thread $thread, int $after): array;

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}|null, mixed, array{offset: int, live: bool}>
     */
    public function followThread(Thread $thread, int $after, int $intervalMilliseconds, int $idleMilliseconds, int $heartbeatMilliseconds = 0): Generator;
}
