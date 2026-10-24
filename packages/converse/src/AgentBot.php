<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Converse\Ai\Toolset;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Scheduling\Schedule;

class AgentBot extends Bot
{
    /** @var array<int, class-string<Tool>> */
    protected array $withoutTools = [];

    /** @var Collection<string, Schedule> */
    protected Collection $schedules;

    protected bool $subscribeToMentions = true;

    /**
     * @param  class-string<Agent>|Closure  $agent
     */
    public function __construct(string $name, protected Closure|string $agent)
    {
        parent::__construct($name);

        $this->schedules = collect();
    }

    /**
     * @return class-string<Agent>|Closure
     */
    public function agentResolver(): string|Closure
    {
        return $this->agent;
    }

    /**
     * @return Collection<int, class-string|Closure>
     */
    public function handlers(): Collection
    {
        return collect([$this->agent, ...parent::handlers()]);
    }

    public function schedule(string $prompt, ?string $name = null): Schedule
    {
        $name ??= $this->defaultScheduleName();

        if ($this->schedules->has($name)) {
            throw new InvalidBotException("Bot [{$this->name}] already has a schedule named [{$name}]. Name each schedule when a bot has more than one.");
        }

        return $this->schedules[$name] = new Schedule($this, $name, $prompt);
    }

    /**
     * @return Collection<string, Schedule>
     */
    public function schedules(): Collection
    {
        return $this->schedules;
    }

    public function unsubscribeFromMentions(): static
    {
        $this->subscribeToMentions = false;

        return $this;
    }

    public function subscribesToMentions(): bool
    {
        return $this->subscribeToMentions;
    }

    public function tools(): Toolset
    {
        return Toolset::default()->without(...$this->withoutTools);
    }

    /**
     * @param  iterable<int, class-string<Tool>>|class-string<Tool>  $tools
     */
    public function withoutTools(iterable|string $tools): static
    {
        $tools = is_string($tools) ? [$tools] : [...$tools];

        foreach ($tools as $tool) {
            if (! is_subclass_of($tool, Tool::class)) {
                throw new InvalidBotException("Bot [{$this->name}] cannot exclude [{$tool}], which is not a Laravel AI tool.");
            }
        }

        $this->withoutTools = array_values(array_unique([...$this->withoutTools, ...$tools]));

        return $this;
    }

    protected function defaultScheduleName(): string
    {
        $name = Str::slug($this->name);

        return $name === ''
            ? throw new InvalidBotException("Unable to derive a name for a schedule on bot [{$this->name}]. Pass one explicitly.")
            : $name;
    }
}
