<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Pagination\Cursor;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\RunState;
use Laravel\Converse\Thread;

class WebThreadSnapshot
{
    public function __construct(
        protected RunStore $runs,
        protected WebTranscript $transcript,
    ) {}

    /**
     * @internal
     *
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: Cursor|null, offset: int}
     */
    public function for(Thread $thread, int $perPage = ChatProps::PER_PAGE): array
    {
        $offset = $this->offset($thread);
        $page = $this->transcript->page($thread, $perPage);

        return ['messages' => $page['messages'], 'interrupts' => $page['interrupts'], 'nextCursor' => $page['nextCursor'], 'offset' => $offset];
    }

    protected function offset(Thread $thread): int
    {
        return $this->unfinishedRun($thread)?->offset() ?? $this->runs->lastEventSequence($thread);
    }

    protected function unfinishedRun(Thread $thread): ?RunState
    {
        return $this->runs->liveRun($thread) ?? $this->interruptedRun($thread);
    }

    protected function interruptedRun(Thread $thread): ?RunState
    {
        $run = $this->runs->lastInterruptedRun($thread);

        if (! $run instanceof RunState || $run->promptMessageId() === null) {
            return null;
        }

        return $run->promptMessageId() === $this->transcript->newestUserMessageId($thread) ? $run : null;
    }
}
