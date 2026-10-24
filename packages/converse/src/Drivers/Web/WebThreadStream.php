<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Generator;
use Illuminate\Support\Str;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Enums\WebTransport;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\RunState;
use Laravel\Converse\Thread;

class WebThreadStream
{
    public function __construct(
        protected RunStore $runs,
        protected WebThreadSnapshot $snapshots,
    ) {}

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}|null, mixed, array{offset: int, live: bool}>
     */
    public function open(Thread $thread, int $offset, bool $following, WebChatOptions $options): Generator
    {
        if ($following && $options->transport() === WebTransport::Stream) {
            return $this->follow($thread, $offset, $this->runs->lastEventSequence($thread), $options);
        }

        return $this->catchUp($thread, $offset, $this->runs->liveRun($thread) instanceof RunState, $this->runs->lastEventSequence($thread));
    }

    protected function needsSnapshot(Thread $thread, int $offset, int $lastSequence): bool
    {
        if ($offset === $lastSequence) {
            return false;
        }

        if ($offset > $lastSequence) {
            return true;
        }

        $firstSequence = $this->runs->firstAvailableSequence($thread);

        if ($firstSequence === null) {
            return true;
        }

        return $offset < $firstSequence - 1;
    }

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}, mixed, int>
     */
    protected function snapshot(Thread $thread): Generator
    {
        $snapshot = $this->snapshots->for($thread);

        yield ['sequence' => $snapshot['offset'], 'payload' => ['type' => 'MESSAGES_SNAPSHOT', 'messages' => $snapshot['messages']]];

        if ($snapshot['interrupts'] !== []) {
            yield ['sequence' => $snapshot['offset'], 'payload' => TurnEvents::runFinished($thread->id(), (string) Str::uuid7(), $snapshot['interrupts'])];
        }

        return $snapshot['offset'];
    }

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}, mixed, array{offset: int, live: bool}>
     */
    protected function catchUp(Thread $thread, int $offset, bool $live, int $lastSequence): Generator
    {
        if ($this->needsSnapshot($thread, $offset, $lastSequence)) {
            $offset = yield from $this->snapshot($thread);

            $live = $this->runs->liveRun($thread) instanceof RunState;
            $lastSequence = $this->runs->lastEventSequence($thread);
        }

        yield from $this->eventsUpTo($thread, $offset, $lastSequence);

        return ['offset' => $lastSequence, 'live' => $live];
    }

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}>
     */
    protected function eventsUpTo(Thread $thread, int $offset, int $lastSequence): Generator
    {
        while ($offset < $lastSequence) {
            $events = $this->runs->readThread($thread, $offset);

            if ($events === []) {
                return;
            }

            yield from array_filter($events, fn (array $event): bool => $event['sequence'] <= $lastSequence);

            $offset = $events[array_key_last($events)]['sequence'];
        }
    }

    /**
     * @return Generator<int, array{sequence: int, payload: array<mixed>}|null, mixed, array{offset: int, live: bool}>
     */
    protected function follow(Thread $thread, int $offset, int $lastSequence, WebChatOptions $options): Generator
    {
        if ($this->needsSnapshot($thread, $offset, $lastSequence)) {
            $offset = yield from $this->snapshot($thread);
        }

        return yield from $this->runs->followThread(
            $thread,
            $offset,
            intervalMilliseconds: $options->streamIntervalMilliseconds(),
            idleMilliseconds: $options->followWindowMilliseconds(),
            heartbeatMilliseconds: $options->streamHeartbeatMilliseconds(),
        );
    }
}
