<?php

declare(strict_types=1);

namespace Laravel\Converse\Runs;

use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Contracts\Turn;
use Laravel\Converse\Enums\RunStatus;
use Laravel\Converse\Exceptions\RunNoLongerLiveException;
use Laravel\Converse\ThreadEventRange;
use Throwable;

class TurnRunner
{
    public function __construct(protected RunStore $runs) {}

    public function recordPrompt(Turn $turn, string $runId): ThreadEventRange
    {
        $events = $turn->recordPrompt();

        return $this->runs->appendPrompt($runId, $turn->promptMessageId(), $events);
    }

    public function run(Turn $turn, string $runId, int $timeoutSeconds, int $idleTimeoutSeconds): RunStatus
    {
        if (! $this->runs->start($runId, $timeoutSeconds)) {
            return RunStatus::Failed;
        }

        $events = new RunEventWriter($this->runs, $runId, $idleTimeoutSeconds);

        try {
            $turn->run($events);

            $events->flush();

            $status = RunStatus::Completed;
        } catch (RunNoLongerLiveException) {
            return $this->runs->status($runId);
        } catch (Throwable $exception) {
            if (! $exception instanceof StreamErrorException) {
                report($exception);
            }

            $events->fail($turn->failed($exception));

            $status = RunStatus::Failed;
        }

        $this->runs->finish($runId, $status);

        return $this->runs->status($runId);
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    public function abandon(string $runId, ?array $error = null): void
    {
        try {
            $this->runs->append($runId, [$error ?? TurnEvents::error()]);
        } catch (RunNoLongerLiveException) {
            return;
        }

        $this->runs->finish($runId, RunStatus::Failed);
    }
}
