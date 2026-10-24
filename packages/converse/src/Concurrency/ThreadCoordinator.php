<?php

declare(strict_types=1);

namespace Laravel\Converse\Concurrency;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Laravel\Converse\Concerns\BuildsCacheKeys;
use Laravel\Converse\Exceptions\UnsupportedCacheStoreException;
use Laravel\Converse\Thread;

class ThreadCoordinator
{
    use BuildsCacheKeys;

    public function __construct(protected Repository $cache) {}

    public function wasProcessed(Thread $thread, string $eventId): bool
    {
        return $this->cache->has($this->cacheKey('dedupe', $thread->bot(), $thread->connection(), $eventId));
    }

    public function markProcessed(Thread $thread, string $eventId): void
    {
        $this->cache->put($this->cacheKey('dedupe', $thread->bot(), $thread->connection(), $eventId), true, $this->dedupeSeconds());
    }

    protected function lock(Thread $thread): Lock
    {
        return $this->lockProvider()->lock($this->threadKey('lock', $thread), $this->lockSeconds());
    }

    public function withThreadLock(Thread $thread, Closure $callback): mixed
    {
        return $this->lock($thread)->block($this->waitSeconds(), $callback);
    }

    public function withConcurrencyLock(Thread $thread, Closure $callback): mixed
    {
        return $this->blocking($this->threadKey('concurrency-lock', $thread), $callback);
    }

    public function isScheduled(Thread $thread): bool
    {
        return $this->cache->has($this->threadKey('scheduled', $thread));
    }

    public function markScheduled(Thread $thread, int $debounce): void
    {
        $this->cache->put($this->threadKey('scheduled', $thread), $this->arrivals($thread), $this->coordinationSeconds($debounce));
    }

    public function scheduledArrivals(Thread $thread): ?int
    {
        return $this->counter($this->threadKey('scheduled', $thread));
    }

    public function markArrival(Thread $thread): void
    {
        $this->cache->put($this->threadKey('arrival', $thread), $this->arrivals($thread) + 1, $this->coordinationSeconds());
    }

    public function arrivals(Thread $thread): int
    {
        return $this->counter($this->threadKey('arrival', $thread)) ?? 0;
    }

    public function clearScheduled(Thread $thread): void
    {
        $this->cache->forget($this->threadKey('scheduled', $thread));
    }

    public function failures(Thread $thread): int
    {
        return $this->counter($this->threadKey('failure', $thread)) ?? 0;
    }

    public function markFailure(Thread $thread): int
    {
        $failures = $this->failures($thread) + 1;

        $this->cache->put($this->threadKey('failure', $thread), $failures, $this->coordinationSeconds());

        return $failures;
    }

    public function clearFailure(Thread $thread): void
    {
        $this->cache->forget($this->threadKey('failure', $thread));
    }

    public function isSteered(Thread $thread): bool
    {
        return $this->cache->has($this->threadKey('steer', $thread));
    }

    public function markSteered(Thread $thread): void
    {
        $this->cache->put($this->threadKey('steer', $thread), true, $this->coordinationSeconds());
    }

    public function clearSteered(Thread $thread): void
    {
        $this->cache->forget($this->threadKey('steer', $thread));
    }

    public function withMetaLock(Thread $thread, Closure $callback): mixed
    {
        return $this->blocking($this->threadKey('meta-lock', $thread), $callback);
    }

    protected function counter(string $key): ?int
    {
        $count = $this->cache->get($key);

        return is_numeric($count) ? (int) $count : null;
    }

    protected function blocking(string $key, Closure $callback): mixed
    {
        return $this->lockProvider()->lock($key, $this->lockSeconds())->block($this->waitSeconds(), $callback);
    }

    protected function threadKey(string $prefix, Thread $thread): string
    {
        return $this->cacheKey($prefix, $thread->bot(), $thread->encode());
    }

    protected function coordinationSeconds(int $debounce = 0): int
    {
        return $debounce + (int) config('converse.processing.timeout') + $this->lockSeconds();
    }

    protected function dedupeSeconds(): int
    {
        return max(1, (int) config('converse.processing.dedupe_ttl'));
    }

    protected function waitSeconds(): int
    {
        return (int) config('converse.processing.lock_wait');
    }

    protected function lockSeconds(): int
    {
        return max(1, (int) config('converse.processing.lock_ttl'));
    }

    protected function lockProvider(): LockProvider
    {
        $store = $this->cache->getStore();
        $name = config('converse.cache.store') ?? config()->string('cache.default');

        return $store instanceof LockProvider
            ? $store
            : throw new UnsupportedCacheStoreException("The [{$name}] cache store does not support locks.");
    }
}
