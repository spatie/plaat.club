<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Support\Str;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Turn;
use Laravel\Converse\Exceptions\DuplicateMessageException;
use Laravel\Converse\Routing\EventRouter;
use Laravel\Converse\Runs\RunEventWriter;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\ThreadContext;
use Throwable;

class HandlerTurn implements Turn
{
    protected bool $started = false;

    protected ?string $promptMessageId = null;

    public function __construct(
        protected Bot $bot,
        protected Message|Command $event,
        protected string $runId,
        protected RunWriters $writers,
        protected ThreadContext $context,
        protected EventRouter $router,
        protected ConversationStore $conversations,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws DuplicateMessageException
     */
    public function recordPrompt(): array
    {
        $this->store();

        $this->promptMessageId = $this->eventId() ?? (string) Str::uuid7();

        return TurnEvents::message($this->promptMessageId, 'user', $this->text(), clientMessageId: $this->eventId());
    }

    public function promptMessageId(): ?string
    {
        return $this->promptMessageId;
    }

    public function run(RunEventWriter $events): void
    {
        $thread = $this->event->thread();

        $events->write(TurnEvents::runStarted($thread->id(), $this->runId));

        $this->started = true;

        $this->writers->writingTo($thread, $events, fn () => $this->context->for(
            $thread,
            fn () => $this->router->route($this->bot, $this->event, $thread),
        ));

        $events->write(TurnEvents::runFinished($thread->id(), $this->runId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function failed(Throwable $exception): array
    {
        if ($this->started) {
            return [TurnEvents::error()];
        }

        return [TurnEvents::runStarted($this->event->thread()->id(), $this->runId), TurnEvents::error()];
    }

    protected function store(): void
    {
        $stored = $this->event instanceof Command
            ? $this->conversations->storeCommand($this->event)
            : $this->storeMessage($this->event);

        if (! $stored) {
            throw DuplicateMessageException::forMessage($this->eventId());
        }
    }

    protected function storeMessage(Message $message): bool
    {
        if (! $this->conversations->queueUserMessage($message)) {
            return false;
        }

        $this->conversations->pendingMessages($message->thread())
            ->filter(fn (Message $pending): bool => $pending->id() === $message->id())
            ->each(fn (Message $pending) => $this->conversations->completeUserMessage($pending));

        return true;
    }

    protected function eventId(): ?string
    {
        return $this->event instanceof Command ? $this->event->eventId() : $this->event->id();
    }

    protected function text(): string
    {
        return $this->event instanceof Command
            ? trim('/'.$this->event->name().' '.$this->event->text())
            : $this->event->text();
    }
}
