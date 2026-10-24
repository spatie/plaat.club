<?php

declare(strict_types=1);

namespace Laravel\Converse\Scheduling;

use Closure;
use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\ManagesAttributes;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Jobs\RunSchedule;
use Laravel\Converse\Thread;

class Schedule
{
    use ManagesAttributes;
    use ManagesFrequencies;

    /** @var Collection<int, Destination> */
    protected Collection $destinations;

    /** @var array<int, array{string, array<int|string, mixed>}> */
    protected array $hooks = [];

    public function __construct(protected AgentBot $bot, protected string $name, protected string $prompt)
    {
        $this->destinations = collect();
    }

    public function bot(): AgentBot
    {
        return $this->bot;
    }

    public function scheduleName(): string
    {
        return $this->name;
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    public function in(string $connection, string $target): static
    {
        $this->destinations->push(new Destination($this->addressable($connection), $target));

        return $this;
    }

    /** @internal */
    public function validate(): void
    {
        if ($this->destinations->isEmpty()) {
            throw new InvalidBotException("Schedule [{$this->name}] on bot [{$this->bot->name()}] has no destination. Call [in()] when defining it.");
        }
    }

    /** @internal */
    public function register(LaravelSchedule $schedule): CallbackEvent
    {
        $this->validate();

        $job = new RunSchedule($this->bot->name(), $this->name);

        $this->description ??= $job->displayName();

        $configured = config('converse.schedules.timezone');

        if ($this->timezone === null && (is_string($configured) || $configured instanceof DateTimeZone)) {
            $this->timezone($configured);
        }

        if (! $this->withoutOverlapping) {
            $this->withoutOverlapping();
        }

        $event = $schedule->job($job);

        $this->mergeAttributes($event);

        return $event;
    }

    /** @internal */
    public function run(?string $token = null): void
    {
        $this->validate();

        $token ??= (string) Str::uuid();

        foreach ($this->threads() as $thread) {
            $thread->prompt($this->prompt, key: "{$this->name}:{$token}");
        }
    }

    /**
     * @return Collection<int, Thread>
     */
    public function threads(): Collection
    {
        $resolver = Container::getInstance()->make(DestinationResolver::class);

        return $this->destinations->map(fn (Destination $destination): Thread => $resolver->thread($this->bot, $destination));
    }

    /**
     * @return Collection<int, string>
     */
    public function destinations(): Collection
    {
        return $this->destinations->map(fn (Destination $destination): string => (string) $destination);
    }

    public function withoutOverlapping(int $expiresAt = 1440, bool $releaseOnTerminationSignals = true): static
    {
        $this->withoutOverlapping = true;
        $this->expiresAt = $expiresAt;
        $this->releaseOnTerminationSignals = $releaseOnTerminationSignals;

        return $this;
    }

    public function before(Closure $callback): static
    {
        return $this->hook('before', $callback);
    }

    public function after(Closure $callback): static
    {
        return $this->hook('after', $callback);
    }

    public function then(Closure $callback): static
    {
        return $this->hook('then', $callback);
    }

    public function onSuccess(Closure $callback): static
    {
        return $this->hook('onSuccess', $callback);
    }

    public function onFailure(Closure $callback): static
    {
        return $this->hook('onFailure', $callback);
    }

    public function pingBefore(string $url): static
    {
        return $this->hook('pingBefore', $url);
    }

    public function pingBeforeIf(bool $value, string $url): static
    {
        return $this->hook('pingBeforeIf', $value, $url);
    }

    public function thenPing(string $url): static
    {
        return $this->hook('thenPing', $url);
    }

    public function thenPingIf(bool $value, string $url): static
    {
        return $this->hook('thenPingIf', $value, $url);
    }

    public function pingOnSuccess(string $url): static
    {
        return $this->hook('pingOnSuccess', $url);
    }

    public function pingOnSuccessIf(bool $value, string $url): static
    {
        return $this->hook('pingOnSuccessIf', $value, $url);
    }

    public function pingOnFailure(string $url): static
    {
        return $this->hook('pingOnFailure', $url);
    }

    public function pingOnFailureIf(bool $value, string $url): static
    {
        return $this->hook('pingOnFailureIf', $value, $url);
    }

    public function runInBackground(): never
    {
        throw new InvalidBotException("Schedule [{$this->name}] may not use [runInBackground]. A Converse schedule already runs in the background on your queue.");
    }

    protected function addressable(string $connection): string
    {
        if (! $this->bot->hasConnection($connection)) {
            throw InvalidBotException::notBound($this->bot->name(), $connection);
        }

        return $connection;
    }

    protected function hook(string $method, mixed ...$arguments): static
    {
        $this->hooks[] = [$method, $arguments];

        return $this;
    }

    protected function mergeAttributes(Event $event): void
    {
        $event->expression = $this->expression;
        $event->repeatSeconds = $this->repeatSeconds;

        if ($this->description !== null) {
            $event->name($this->description);
        }

        if ($this->attributes !== []) {
            $event->attributes = $this->attributes;
        }

        if ($this->timezone !== null) {
            $event->timezone($this->timezone);
        }

        if ($this->user !== null) {
            $event->user = $this->user;
        }

        if ($this->environments !== []) {
            $event->environments($this->environments);
        }

        if ($this->evenInMaintenanceMode) {
            $event->evenInMaintenanceMode();
        }

        if ($this->evenWhenPaused) {
            $event->evenWhenPaused();
        }

        if ($this->withoutOverlapping) {
            $event->withoutOverlapping($this->expiresAt, $this->releaseOnTerminationSignals);
        }

        if ($this->onOneServer) {
            $event->onOneServer();
        }

        foreach ($this->filters as $filter) {
            $event->when($filter);
        }

        foreach ($this->rejects as $reject) {
            $event->skip($reject);
        }

        foreach ($this->hooks as [$method, $arguments]) {
            $event->{$method}(...$arguments);
        }
    }
}
