<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Converse\Exceptions\BotNotRegisteredException;
use Laravel\Converse\Jobs\RunSchedule;
use Laravel\Converse\Scheduling\Schedule;
use Laravel\Converse\Scheduling\ScheduleRegistrar;

#[Signature('converse:schedule:run {bot : The bot the schedule belongs to} {name : The schedule name}')]
#[Description('Fire one bot schedule now through the queue')]
class ScheduleRunCommand extends Command
{
    public function handle(ScheduleRegistrar $schedules): int
    {
        $bot = (string) $this->argument('bot');
        $name = (string) $this->argument('name');

        try {
            $schedule = $schedules->find($bot, $name);
        } catch (BotNotRegisteredException $botNotRegisteredException) {
            $this->components->error($botNotRegisteredException->getMessage());

            return static::FAILURE;
        }

        if (! $schedule instanceof Schedule) {
            $this->components->error("Bot [{$bot}] has no schedule named [{$name}].");
            $this->components->bulletList($schedules->names($bot)->all());

            return static::FAILURE;
        }

        RunSchedule::dispatch($bot, $name);

        $this->components->info("Schedule [{$name}] on bot [{$bot}] was dispatched.");

        return static::SUCCESS;
    }
}
