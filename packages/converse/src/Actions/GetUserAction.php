<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Illuminate\Contracts\Cache\Repository;
use Laravel\Converse\Concerns\BuildsCacheKeys;
use Laravel\Converse\Contracts\Drivers\SupportsGetUsers;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class GetUserAction extends Action
{
    use BuildsCacheKeys;

    protected const int FOUND_SECONDS = 3600;

    protected const int MISSING_SECONDS = 300;

    public function __construct(protected Repository $cache) {}

    public function handle(Thread $thread, User|string $user): ?User
    {
        $id = $user instanceof User ? $user->id() : $user;
        $driver = $this->optionalDriverFor($thread, SupportsGetUsers::class);

        if ($id === '' || ! $driver instanceof SupportsGetUsers) {
            return null;
        }

        $key = $this->cacheKey('user-info', null, $thread->connection(), $id);

        $cached = $this->cache->get($key, function () use ($key, $driver, $id): array|false {
            $found = $driver->getUser($id)?->toArray() ?? false;

            $this->cache->put($key, $found, $found === false ? static::MISSING_SECONDS : static::FOUND_SECONDS);

            return $found;
        });

        return is_array($cached) ? User::fromStoredArray($cached) : null;
    }
}
