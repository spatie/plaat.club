<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Bot;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Scheduling\Schedule;

#[Signature('converse:list')]
#[Description('List the bots registered by your application')]
class BotListCommand extends Command
{
    public function handle(BotRegistrar $registrar): int
    {
        $bots = $registrar->bots();

        if ($bots->isEmpty()) {
            $this->components->info('No bots have been registered. Define one in [routes/bot.php].');

            return static::SUCCESS;
        }

        $this->table(
            ['Bot', 'Kind', 'Handlers', 'Connections', 'Debounce', 'Schedules'],
            $bots->map(fn (Bot $bot): array => [
                $bot->name(),
                $bot instanceof AgentBot ? 'agent' : 'handler',
                $this->handlers($bot),
                $bot->connectionsWithTerminal()->implode(', '),
                $bot->debounceSeconds().'s',
                $this->schedules($bot),
            ])->values()->all(),
        );

        return static::SUCCESS;
    }

    protected function handlers(Bot $bot): string
    {
        $handlers = $bot->handlers()
            ->map(fn (string|Closure $handler): string => $handler instanceof Closure ? 'Closure' : $handler)
            ->implode(', ');

        return $handlers === '' ? '-' : $handlers;
    }

    protected function schedules(Bot $bot): string
    {
        $schedules = $bot->schedules()->map(fn (Schedule $schedule): string => $schedule->scheduleName())->implode(', ');

        return $schedules === '' ? '-' : $schedules;
    }
}
