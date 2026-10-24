<?php

declare(strict_types=1);

namespace Laravel\Converse\Concurrency;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\MessageUpdated;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Transcribable;
use Laravel\Converse\Exceptions\InterruptedException;
use Laravel\Converse\Exceptions\SteeredException;
use Laravel\Converse\Jobs\ProcessPendingMessages;
use Laravel\Converse\Routing\EventRouter;
use Laravel\Converse\Thread;
use Throwable;

class ConcurrencyManager
{
    public const int SETTLE_SECONDS = 1;

    public const int SETTLE_LIMIT = 10;

    protected const string FAILURE_MESSAGE = 'Something went wrong while handling that message.';

    public function __construct(
        protected BotRegistrar $registrar,
        protected ThreadCoordinator $coordinator,
        protected ConversationStore $conversations,
        protected EventRouter $router,
        protected Dispatcher $bus,
    ) {}

    /**
     * @param  (Closure(Message): Message)|null  $stage
     */
    public function dispatch(Bot $bot, BotEvent $event, ?Closure $stage = null): void
    {
        if ($event instanceof Message) {
            $this->enqueue($bot, $event, $stage);

            return;
        }

        if ($stage instanceof Closure && $event instanceof MessageUpdated && $this->stages($bot, $event->message())) {
            $event = $event->withMessage($stage($event->message()));
        }

        $this->process($bot, $event);
    }

    /**
     * @param  (Closure(Message): Message)|null  $stage
     */
    protected function enqueue(Bot $bot, Message $message, ?Closure $stage = null): void
    {
        if (! $this->listens($bot, $message)) {
            return;
        }

        $message = $message->withThread($message->thread()->withBot($bot->name()));
        $message = $stage instanceof Closure ? $stage($message) : $message;

        $scheduled = $this->coordinator->withConcurrencyLock($message->thread(), function () use ($bot, $message): bool {
            $queued = $this->conversations->queueUserMessage($message);

            if ($queued) {
                $this->coordinator->markArrival($message->thread());
            }

            if ($this->coordinator->isScheduled($message->thread())) {
                if ($queued && $bot instanceof AgentBot && ! $message->isScheduled()) {
                    $this->coordinator->markSteered($message->thread());
                }

                return false;
            }

            if (! $queued && ! $this->conversations->hasPendingMessages($message->thread())) {
                return false;
            }

            $this->coordinator->markScheduled($message->thread(), $bot->debounceSeconds());

            return true;
        });

        if ($scheduled !== true) {
            return;
        }

        $job = new ProcessPendingMessages($message->thread());
        $debounce = $bot->debounceSeconds();

        if ($debounce > 0) {
            $job->delay($debounce);
        }

        $this->queue($job);
    }

    public function processPending(Thread $thread, int $settled): void
    {
        $bot = $this->registrar->get((string) $thread->bot());

        if ($this->settling($bot, $thread, $settled)) {
            return;
        }

        $this->coordinator->withThreadLock($thread, function () use ($bot, $thread): void {
            $this->drain($bot, $thread);
        });
    }

    protected function process(Bot $bot, BotEvent $event): void
    {
        $event = $event->withThread($event->thread()->withBot($bot->name()));

        $this->coordinator->withThreadLock($event->thread(), function () use ($bot, $event): void {
            if ($event->eventId() !== null && $this->coordinator->wasProcessed($event->thread(), $event->eventId())) {
                return;
            }

            $thread = $this->threadFor($bot, $event);

            if ($event instanceof Transcribable) {
                $event->transcribe($this->conversations, $bot, $thread);
            }

            $this->router->route($bot, $event, $thread);

            if ($event->eventId() !== null) {
                $this->coordinator->markProcessed($event->thread(), $event->eventId());
            }
        });
    }

    public function release(Thread $thread): void
    {
        $this->coordinator->clearScheduled($thread);
    }

    protected function listens(Bot $bot, Message $message): bool
    {
        return ! $message->user()->isSelf() && $bot->listensTo($message, $this->threadFor($bot, $message));
    }

    protected function stages(Bot $bot, Message $message): bool
    {
        return ! $message->user()->isSelf()
            && ($bot->handlerFor(MessageUpdated::class) !== null || $this->listens($bot, $message));
    }

    protected function settling(Bot $bot, Thread $thread, int $settled): bool
    {
        if ($settled >= static::SETTLE_LIMIT) {
            return false;
        }

        $resettled = $this->coordinator->withConcurrencyLock($thread, function () use ($bot, $thread): bool {
            $scheduled = $this->coordinator->scheduledArrivals($thread);

            if ($scheduled === null || $this->coordinator->arrivals($thread) <= $scheduled) {
                return false;
            }

            $this->coordinator->markScheduled($thread, $bot->debounceSeconds());

            return true;
        });

        if ($resettled !== true) {
            return false;
        }

        $this->queue(new ProcessPendingMessages($thread, $settled + 1)->delay(static::SETTLE_SECONDS));

        return true;
    }

    protected function queue(ProcessPendingMessages $job): void
    {
        try {
            $this->bus->dispatch($job);
        } catch (Throwable $throwable) {
            $this->release($job->thread());

            throw $throwable;
        }
    }

    protected function drain(Bot $bot, Thread $thread): void
    {
        while (($batch = $this->batch($thread)) instanceof Collection) {
            [$message, $others] = $this->driving($batch);

            try {
                $this->route($bot, $message, $others);
            } catch (SteeredException) {
                continue;
            } catch (InterruptedException $interruptedException) {
                $this->coordinator->clearFailure($thread);
                $this->complete($others->concat([$message]));

                throw $interruptedException;
            } catch (Throwable $throwable) {
                $this->giveUp($bot, $message, $others);

                throw $throwable;
            }

            $this->coordinator->clearFailure($thread);
            $this->complete($others->concat([$message]));
        }
    }

    /**
     * @param  Collection<int, Message>  $batch
     * @return array{Message, Collection<int, Message>}
     */
    protected function driving(Collection $batch): array
    {
        /** @var Message $message */
        $message = $batch->last(fn (Message $message): bool => ! $message->isScheduled()) ?? $batch->last();

        return [$message, $batch->reject(fn (Message $other): bool => $other === $message)->values()];
    }

    /**
     * @param  Collection<int, Message>  $others
     */
    protected function giveUp(Bot $bot, Message $message, Collection $others): void
    {
        if ($this->coordinator->markFailure($message->thread()) < $this->attempts()) {
            return;
        }

        $this->coordinator->clearFailure($message->thread());
        $this->complete($others->concat([$message]));

        rescue(function () use ($bot, $message): void {
            $this->threadFor($bot, $message)->send(static::FAILURE_MESSAGE);
        }, report: false);
    }

    protected function attempts(): int
    {
        return max(1, (int) config('converse.processing.max_attempts'));
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function complete(Collection $messages): void
    {
        foreach ($messages as $message) {
            $this->conversations->completeUserMessage($message);
        }
    }

    /**
     * @param  Collection<int, Message>  $others
     */
    protected function route(Bot $bot, Message $message, Collection $others): void
    {
        $this->router->route($bot, $message, $this->threadFor($bot, $message), $others);
    }

    protected function threadFor(Bot $bot, BotEvent $event): Thread
    {
        return $event->thread()
            ->withBot($bot->name())
            ->withUser($event->user())
            ->withDirectMessage($event->isDirectMessage());
    }

    /**
     * @return Collection<int, Message>|null
     */
    protected function batch(Thread $thread): ?Collection
    {
        $batch = $this->coordinator->withConcurrencyLock($thread, function () use ($thread): ?Collection {
            $this->coordinator->clearSteered($thread);

            $pending = $this->conversations->pendingMessages($thread);

            if ($pending->isEmpty()) {
                $this->coordinator->clearScheduled($thread);

                return null;
            }

            return $pending;
        });

        return $batch instanceof Collection ? $batch : null;
    }
}
