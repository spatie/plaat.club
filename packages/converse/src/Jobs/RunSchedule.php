<?php

declare(strict_types=1);

namespace Laravel\Converse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Jobs\Concerns\RunsOnConverseQueue;
use Laravel\Converse\Scheduling\ScheduleRegistrar;

class RunSchedule implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsOnConverseQueue;

    protected string $token;

    public function __construct(protected string $bot, protected string $schedule)
    {
        $this->onConverseQueue();
        $this->token = (string) Str::uuid();
    }

    public function bot(): string
    {
        return $this->bot;
    }

    public function schedule(): string
    {
        return $this->schedule;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function handle(ScheduleRegistrar $schedules): void
    {
        $schedule = $schedules->find($this->bot, $this->schedule)
            ?? throw new InvalidBotException("Bot [{$this->bot}] has no schedule named [{$this->schedule}].");

        $schedule->run($this->token);
    }

    public function displayName(): string
    {
        return "converse:{$this->bot}:{$this->schedule}";
    }
}
