<?php

declare(strict_types=1);

namespace Laravel\Converse\Facades;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\Contracts\Agent;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Ai\Toolset;
use Laravel\Converse\Bot;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\Drivers\Driver;
use Laravel\Converse\Drivers\Web\ChatProps;
use Laravel\Converse\Testing\ConverseFake;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

/**
 * @method static Bot bot(string $name, class-string|Closure ...$handlers)
 * @method static AgentBot agent(string $name, class-string<Agent>|Closure $agent)
 * @method static void resolveUsersUsing(Closure $callback)
 * @method static ChatProps chat(string $bot, bool $new = false, ?string $connection = null)
 * @method static bool has(string $name)
 * @method static Bot get(string $name)
 * @method static Collection<string, Bot> bots()
 * @method static Toolset tools()
 * @method static Driver connection(string $name, ?string $bot = null)
 * @method static void extend(string $driver, Closure $callback)
 * @method static Thread directMessage(string $connection, User|string $recipient, ?string $bot = null)
 * @method static ConverseFake fake()
 * @method static void incoming(string $bot, BotEvent $event)
 *
 * @see BotRegistrar
 */
class Converse extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BotRegistrar::class;
    }
}
