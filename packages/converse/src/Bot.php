<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\MessageDeleted;
use Laravel\Converse\BotEvents\MessageUpdated;
use Laravel\Converse\BotEvents\Reaction;
use Laravel\Converse\Contracts\HandlesActions;
use Laravel\Converse\Contracts\HandlesCommands;
use Laravel\Converse\Contracts\HandlesDirectMessages;
use Laravel\Converse\Contracts\HandlesFallback;
use Laravel\Converse\Contracts\HandlesMentions;
use Laravel\Converse\Contracts\HandlesMessageDeletions;
use Laravel\Converse\Contracts\HandlesMessages;
use Laravel\Converse\Contracts\HandlesMessageUpdates;
use Laravel\Converse\Contracts\HandlesReactions;
use Laravel\Converse\Contracts\HandlesSubscribedMessages;
use Laravel\Converse\Drivers\Terminal\TerminalDriver;
use Laravel\Converse\Enums\RoutingSlot;
use Laravel\Converse\Enums\WebTransport;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Scheduling\Destination;
use Laravel\Converse\Scheduling\DestinationResolver;
use Laravel\Converse\Scheduling\Schedule;

class Bot
{
    /**
     * @var Collection<int, string>
     */
    protected Collection $connections;

    /** @var array<string, array{class-string, string}> */
    protected array $slots = [];

    /** @var Collection<int, class-string|Closure> */
    protected Collection $handlers;

    protected ?int $debounce = null;

    protected ?WebTransport $webTransport = null;

    protected ?float $webInterval = null;

    protected ?bool $webGuests = null;

    /** @var array<int, class-string|object> */
    protected array $middleware = [];

    /**
     * @param  class-string  ...$handlers
     */
    public function __construct(protected string $name, string ...$handlers)
    {
        $this->connections = collect();
        $this->handlers = collect();

        foreach ($handlers as $handler) {
            if (is_subclass_of($handler, Agent::class)) {
                throw InvalidBotException::agentHandler($name);
            }

            $this->handlers->push($handler);
            $this->registerHandler($handler);
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return Collection<int, class-string|Closure>
     */
    public function handlers(): Collection
    {
        return $this->handlers->collect();
    }

    /**
     * @param  string|array<int, string>  $connections
     */
    public function connection(string|array $connections, string ...$additionalConnections): static
    {
        $this->connections = $this->connections
            ->concat(Arr::wrap($connections))
            ->concat($additionalConnections)
            ->unique()
            ->values();

        return $this;
    }

    /**
     * @param  array<int, string>  $connections
     */
    public function connections(array $connections): static
    {
        return $this->connection($connections);
    }

    /**
     * @internal
     *
     * @return Collection<int, string>
     */
    public function getConnections(): Collection
    {
        return $this->connections->collect();
    }

    public function debounce(int $seconds): static
    {
        if ($seconds < 0) {
            throw new InvalidBotException("Debounce [{$seconds}] must be a non-negative integer.");
        }

        $this->debounce = $seconds;

        return $this;
    }

    public function debounceSeconds(): int
    {
        return $this->debounce ?? (int) config('converse.processing.debounce');
    }

    public function poll(?float $interval = null): static
    {
        return $this->useWebTransport(WebTransport::Poll, $interval);
    }

    public function stream(?float $interval = null): static
    {
        return $this->useWebTransport(WebTransport::Stream, $interval);
    }

    public function allowGuests(bool $allow = true): static
    {
        $this->webGuests = $allow;

        return $this;
    }

    /** @internal */
    public function webTransport(): ?WebTransport
    {
        return $this->webTransport;
    }

    /** @internal */
    public function webIntervalSeconds(): ?float
    {
        return $this->webInterval;
    }

    /** @internal */
    public function allowsWebGuests(): ?bool
    {
        return $this->webGuests;
    }

    /**
     * @internal
     *
     * @return array{class-string, string}|null
     */
    public function handlerFor(RoutingSlot|string $slot): ?array
    {
        return $this->slots[$slot instanceof RoutingSlot ? $slot->value : $slot] ?? null;
    }

    public function hasConnection(string $name): bool
    {
        return $this->connectionsWithTerminal()->contains($name);
    }

    public function ensureHasConnection(string $name): void
    {
        if (! $this->hasConnection($name)) {
            throw InvalidBotException::notBound($this->name, $name);
        }
    }

    /**
     * @return Collection<int, string>
     */
    public function connectionsWithTerminal(): Collection
    {
        return $this->connections->collect()->push(TerminalDriver::CONNECTION)->unique()->values();
    }

    /** @internal */
    public function listensTo(Message $message, Thread $thread): bool
    {
        return $this->handlerFor(Message::class) !== null
            || $this->handlerFor(RoutingSlot::Fallback) !== null
            || $this->isAddressedBy($message, $thread);
    }

    /** @internal */
    public function isAddressedBy(Message $message, Thread $thread): bool
    {
        return $message->isScheduled()
            || $message->isMention()
            || $message->isDirectMessage()
            || $thread->isSubscribed()
            || $thread->wasStartedByBot();
    }

    public function directMessage(string $connection, User|string $recipient): Thread
    {
        if (is_string($recipient) && new Destination($connection, $recipient)->isUsername()) {
            return Container::getInstance()->make(DestinationResolver::class)->directMessage($this, $connection, $recipient);
        }

        return $this->registrar()->directMessage($connection, $recipient, $this->name);
    }

    /**
     * @return Collection<string, Schedule>
     */
    public function schedules(): Collection
    {
        return collect();
    }

    public function thread(string $connection, string $channel, ?string $thread = null): Thread
    {
        $this->ensureHasConnection($connection);

        $channel = Container::getInstance()->make(DestinationResolver::class)->channel($this, new Destination($connection, $channel));

        return new Thread($connection, $channel, $thread, bot: $this->name);
    }

    /**
     * @param  class-string|object|array<int, class-string|object>  $middleware
     * @param  class-string|object  ...$additionalMiddleware
     */
    public function middleware(string|object|array $middleware, string|object ...$additionalMiddleware): static
    {
        $this->middleware = array_values([...$this->middleware, ...(is_array($middleware) ? $middleware : [$middleware]), ...$additionalMiddleware]);

        return $this;
    }

    /**
     * @return array<int, class-string|object>
     */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    /**
     * @param  class-string  $handler
     */
    public function handler(string $handler): static
    {
        if ($this->handlers()->contains($handler)) {
            return $this;
        }

        if (is_subclass_of($handler, Agent::class)) {
            throw InvalidBotException::agentHandler($this->name);
        }

        if ($this->handlers->isNotEmpty()) {
            throw new InvalidBotException("Bot [{$this->name}] already has a handler.");
        }

        $this->handlers->push($handler);

        $this->registerHandler($handler);

        return $this;
    }

    /**
     * @param  class-string  $handler
     */
    protected function registerHandler(string $handler): void
    {
        $contracts = [
            HandlesMentions::class => [RoutingSlot::Mention->value, 'handleMention'],
            HandlesDirectMessages::class => [RoutingSlot::DirectMessage->value, 'handleDirectMessage'],
            HandlesSubscribedMessages::class => [RoutingSlot::SubscribedMessage->value, 'handleSubscribedMessage'],
            HandlesFallback::class => [RoutingSlot::Fallback->value, 'handleFallback'],
            HandlesMessages::class => [Message::class, 'handleMessage'],
            HandlesMessageUpdates::class => [MessageUpdated::class, 'handleMessageUpdate'],
            HandlesMessageDeletions::class => [MessageDeleted::class, 'handleMessageDeletion'],
            HandlesActions::class => [Action::class, 'handleAction'],
            HandlesReactions::class => [Reaction::class, 'handleReaction'],
            HandlesCommands::class => [Command::class, 'handleCommand'],
        ];

        foreach ($contracts as $contract => [$slot, $method]) {
            if (is_subclass_of($handler, $contract)) {
                if (isset($this->slots[$slot])) {
                    throw new InvalidBotException("Bot [{$this->name}] has multiple handlers for routing slot [{$slot}].");
                }

                $this->slots[$slot] = [$handler, $method];
            }
        }
    }

    protected function useWebTransport(WebTransport $transport, ?float $interval): static
    {
        if ($interval !== null && $interval <= 0) {
            throw new InvalidBotException(ucfirst($transport->value)." interval [{$interval}] must be greater than zero.");
        }

        $this->webTransport = $transport;
        $this->webInterval = $interval;

        return $this;
    }

    protected function registrar(): BotRegistrar
    {
        return Container::getInstance()->make(BotRegistrar::class);
    }
}
