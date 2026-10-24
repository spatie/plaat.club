<?php

declare(strict_types=1);

namespace Laravel\Converse\Scheduling;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Support\Collection;
use Laravel\Converse\Bot;
use Laravel\Converse\BotRegistrar;

class ScheduleRegistrar
{
    public function __construct(protected BotRegistrar $bots) {}

    /**
     * @return Collection<int, Schedule>
     */
    public function all(): Collection
    {
        return $this->bots->bots()
            ->flatMap(fn (Bot $bot): Collection => $bot->schedules()->values());
    }

    public function find(string $bot, string $name): ?Schedule
    {
        return $this->bots->get($bot)->schedules()->get($name);
    }

    /**
     * @return Collection<int, string>
     */
    public function names(string $bot): Collection
    {
        return $this->bots->get($bot)->schedules()->keys();
    }

    public function isEnabled(): bool
    {
        return config()->boolean('converse.schedules.enabled', true);
    }

    public function register(LaravelSchedule $schedule): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        foreach ($this->all() as $definition) {
            $definition->register($schedule);
        }
    }

    public function validate(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        foreach ($this->all() as $definition) {
            $definition->validate();
        }
    }
}
