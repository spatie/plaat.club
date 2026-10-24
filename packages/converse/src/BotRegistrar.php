<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Converse\Ai\Toolset;
use Laravel\Converse\Concurrency\ConcurrencyManager;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\Drivers\Driver;
use Laravel\Converse\Contracts\Drivers\SupportsOpenDirectMessages;
use Laravel\Converse\Drivers\Web\ChatProps;
use Laravel\Converse\Drivers\Web\WebChat;
use Laravel\Converse\Drivers\Web\WebDriver;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Exceptions\BotNotRegisteredException;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Exceptions\UnsupportedCapabilityException;
use Laravel\Converse\Testing\ConverseFake;

class BotRegistrar
{
    /**
     * @var Collection<string, Bot>
     */
    protected Collection $bots;

    protected ?ConverseFake $fake = null;

    /** @var (Closure(Request, string): ?object)|null */
    protected ?Closure $userResolver = null;

    public function __construct(protected ConverseManager $manager)
    {
        $this->bots = collect();
    }

    /**
     * @param  class-string  ...$handlers
     */
    public function bot(string $name, string ...$handlers): Bot
    {
        $bot = $this->bots->get($name);

        if (! $bot instanceof Bot) {
            return $this->bots[$name] = new Bot($name, ...$handlers);
        }

        if ($handlers !== [] && array_values($handlers) !== $bot->handlers()->all()) {
            throw InvalidBotException::alreadyRegistered($name);
        }

        return $bot;
    }

    /**
     * @param  class-string<Agent>|Closure  $agent
     */
    public function agent(string $name, string|Closure $agent): AgentBot
    {
        $bot = $this->bots->get($name);

        if (! $bot instanceof Bot) {
            return $this->bots[$name] = new AgentBot($name, $agent);
        }

        if (! $bot instanceof AgentBot || $agent !== $bot->agentResolver()) {
            throw InvalidBotException::alreadyRegistered($name);
        }

        return $bot;
    }

    /**
     * @param  Closure(Request, string): ?object  $callback
     */
    public function resolveUsersUsing(Closure $callback): void
    {
        $this->userResolver = $callback;
    }

    /** @internal */
    public function webUser(Request $request, WebDriver $driver): ?object
    {
        return $this->userResolver instanceof Closure
            ? ($this->userResolver)($request, $driver->connection())
            : (Container::getInstance()->make(AuthManager::class)->userResolver())($driver->options()->guard());
    }

    public function chat(string $bot, bool $new = false, ?string $connection = null): ChatProps
    {
        return Container::getInstance()->make(WebChat::class)->for($this->get($bot), $new, $connection);
    }

    public function has(string $name): bool
    {
        return $this->bots->has($name);
    }

    public function get(string $name): Bot
    {
        return $this->bots[$name] ?? throw new BotNotRegisteredException("Bot [{$name}] is not registered.");
    }

    /**
     * @return Collection<string, Bot>
     */
    public function bots(): Collection
    {
        return $this->bots;
    }

    public function tools(): Toolset
    {
        return Toolset::everything();
    }

    /** @internal */
    public function webConnection(string $bot, ?string $connection = null): WebDriver
    {
        $name = $connection ?? $this->get($bot)->getConnections()->first(WebDriver::isWebConnection(...));

        if ($name === null) {
            throw new InvalidBotException("Bot [{$bot}] has no web connection.");
        }

        $this->get($bot)->ensureHasConnection($name);

        $driver = $this->manager->instance($name);

        if (! $driver instanceof WebDriver) {
            throw new InvalidBotException("Bot [{$bot}] has no web connection named [{$name}].");
        }

        return $driver;
    }

    public function connection(string $name, ?string $bot = null): Driver
    {
        if ($bot !== null) {
            $this->get($bot)->ensureHasConnection($name);
        }

        return $this->fake ?? $this->manager->instance($name);
    }

    public function extend(string $driver, Closure $callback): void
    {
        $this->manager->extend($driver, $callback);
    }

    public function fake(): ConverseFake
    {
        return $this->fake ??= new ConverseFake;
    }

    public function directMessage(string $connection, User|string $recipient, ?string $bot = null): Thread
    {
        $driver = $this->connection($connection, $bot);

        if (! $driver instanceof SupportsOpenDirectMessages) {
            throw UnsupportedCapabilityException::for($connection, Capability::OpenDirectMessage);
        }

        $user = $recipient instanceof User ? $recipient : new User($recipient);
        $resolved = $driver->openDirectMessage($user->id());

        return new Thread($connection, $resolved->channel()->id(), $resolved->id(), $user, directMessage: true, bot: $bot);
    }

    public function incoming(string $bot, BotEvent $event): void
    {
        $registered = $this->get($bot);

        $registered->ensureHasConnection($event->thread()->connection());

        Container::getInstance()->make(ConcurrencyManager::class)->dispatch($registered, $event);
    }
}
