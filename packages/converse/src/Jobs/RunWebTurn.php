<?php

declare(strict_types=1);

namespace Laravel\Converse\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\TimeoutExceededException;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\Turn;
use Laravel\Converse\Drivers\Web\WebTurnFactory;
use Laravel\Converse\Exceptions\TurnTimedOutException;
use Laravel\Converse\Jobs\Concerns\RunsOnConverseQueue;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\Runs\TurnRunner;
use Laravel\Converse\Thread;
use Throwable;

#[Tries(1)]
#[FailOnTimeout]
class RunWebTurn implements ShouldQueue
{
    use Queueable;
    use RunsOnConverseQueue;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        protected Thread $thread,
        protected Message|Command $event,
        protected string $runId,
        protected array $input,
        protected ?object $participant,
        int $timeout,
        protected ?string $conversationId = null,
        protected ?string $userMessageId = null,
    ) {
        $this->onConverseQueue();

        $this->timeout = $timeout;
    }

    public function handle(BotRegistrar $registrar, WebTurnFactory $factory, TurnRunner $turns): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $turn = $this->turn($registrar, $factory);

        $options = $registrar->webConnection($this->bot(), $this->thread->connection())->options();

        $turns->run($turn, $this->runId, $options->expirySeconds(), $options->idleTimeoutSeconds());
    }

    public function failed(?Throwable $exception = null): void
    {
        $turns = Container::getInstance()->make(TurnRunner::class);

        if (! $exception instanceof TimeoutExceededException) {
            $turns->abandon($this->runId);

            return;
        }

        report(TurnTimedOutException::forWebTurn($this->bot(), $this->runId, $this->timeout, $exception));

        $turns->abandon($this->runId, TurnEvents::timedOut());
    }

    protected function bot(): string
    {
        return (string) $this->thread->bot();
    }

    protected function turn(BotRegistrar $registrar, WebTurnFactory $factory): Turn
    {
        return $factory->make(
            bot: $registrar->get($this->bot()),
            event: $this->event,
            thread: $this->thread,
            input: AgentUserInteraction::chat($this->input),
            participant: $this->participant,
            runId: $this->runId,
            conversationId: $this->conversationId,
            userMessageId: $this->userMessageId,
        );
    }
}
