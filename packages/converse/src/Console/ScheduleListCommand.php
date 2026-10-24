<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Laravel\Converse\Scheduling\Schedule;
use Laravel\Converse\Scheduling\ScheduleRegistrar;

#[Signature('converse:schedule:list')]
#[Description('List the scheduled turns registered by your bots')]
class ScheduleListCommand extends Command
{
    public function handle(ScheduleRegistrar $schedules, LaravelSchedule $laravel): int
    {
        $definitions = $schedules->all();

        if ($definitions->isEmpty()) {
            $this->components->info('No bot schedules have been registered.');

            return static::SUCCESS;
        }

        $events = collect($laravel->events())->keyBy(fn (Event $event): string => (string) $event->description);

        $this->table(
            ['Bot', 'Schedule', 'Expression', 'Destination', 'Next Due'],
            $definitions->map(function (Schedule $schedule) use ($events): array {
                $event = $events->get((string) $schedule->description);

                return [
                    $schedule->bot()->name(),
                    $schedule->scheduleName(),
                    $schedule->expression,
                    $schedule->destinations()->implode(', '),
                    $event instanceof Event ? $event->nextRunDate()->format('Y-m-d H:i:s T') : '-',
                ];
            })->all(),
        );

        return static::SUCCESS;
    }
}
