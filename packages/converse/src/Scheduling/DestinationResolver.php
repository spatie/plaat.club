<?php

declare(strict_types=1);

namespace Laravel\Converse\Scheduling;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Laravel\Converse\Bot;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Channel;
use Laravel\Converse\Concerns\BuildsCacheKeys;
use Laravel\Converse\Contracts\Drivers\SupportsFindChannels;
use Laravel\Converse\Contracts\Drivers\SupportsFindUsers;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Exceptions\UnsupportedCapabilityException;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class DestinationResolver
{
    use BuildsCacheKeys;

    public function __construct(
        protected BotRegistrar $registrar,
        protected Repository $cache,
    ) {}

    public function thread(Bot $bot, Destination $destination): Thread
    {
        return $destination->isUsername()
            ? $bot->directMessage($destination->connection(), $destination->target())
            : $bot->thread($destination->connection(), $destination->target());
    }

    public function directMessage(Bot $bot, string $connection, string $username): Thread
    {
        $user = new User($this->user($bot, $connection, $username));
        $key = $this->cacheKey('schedule-conversation', null, $connection, $user->id());
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return Thread::decode($cached, $user, directMessage: true, bot: $bot->name());
        }

        $thread = $bot->directMessage($connection, $user);

        $this->cache->put($key, $thread->encode(), $this->seconds());

        return $thread;
    }

    public function channel(Bot $bot, Destination $destination): string
    {
        [$connection, $channel] = [$destination->connection(), $destination->target()];

        if (! $destination->isChannelName()) {
            return $channel;
        }

        return $this->remember('schedule-channel', $connection, $channel, function () use ($bot, $connection, $channel): string {
            $found = $this->find($bot, $connection, $channel);

            return $found instanceof Channel
                ? $found->id()
                : throw new InvalidBotException("Unable to resolve [{$channel}] on the [{$connection}] connection. A channel name resolves through the channels the bot may look up.");
        });
    }

    protected function user(Bot $bot, string $connection, string $username): string
    {
        return $this->remember('schedule-user', $connection, $username, function () use ($bot, $connection, $username): string {
            $found = $this->findUser($bot, $connection, $username);

            return $found instanceof User && ! $found->isAnonymous()
                ? $found->id()
                : throw new InvalidBotException("Unable to resolve [{$username}] on the [{$connection}] connection. A username resolves through the people the bot may look up.");
        });
    }

    protected function find(Bot $bot, string $connection, string $name): ?Channel
    {
        $driver = $this->registrar->connection($connection, $bot->name());

        return $driver instanceof SupportsFindChannels ? $driver->findChannel($name) : null;
    }

    protected function findUser(Bot $bot, string $connection, string $username): ?User
    {
        $driver = $this->registrar->connection($connection, $bot->name());

        if (! $driver instanceof SupportsFindUsers) {
            throw UnsupportedCapabilityException::for($connection, Capability::FindUser);
        }

        return $driver->findUser($username);
    }

    /**
     * @param  Closure(): string  $callback
     */
    protected function remember(string $prefix, string $connection, string $name, Closure $callback): string
    {
        $key = $this->cacheKey($prefix, null, $connection, $name);
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resolved = $callback();

        $this->cache->put($key, $resolved, $this->seconds());

        return $resolved;
    }

    protected function seconds(): int
    {
        return max(1, (int) config('converse.schedules.resolution_ttl', 86400));
    }
}
