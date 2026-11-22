<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Store;

use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Nwidart\MetricsStore\InMemoryStore;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Keeps every series in one entry of a Laravel cache store, so that development needs no Redis.
 * The merge rules are those of InMemoryStore. A merge takes a lock, reads the entry, adds to it
 * and writes it back, so writers wait for each other: use RedisStore in production.
 *
 * It talks to the store directly, not through a cache repository, so that its own reads and
 * writes dispatch no cache events and are not counted as cache operations of the application.
 */
final class CacheStore implements MetricStore
{
    /** How long a lock lives if its owner dies, in seconds. */
    private const LOCK_TTL = 10;

    /**
     * @param  int  $lockWait  seconds a merge waits for the lock; it then throws LockTimeoutException
     */
    public function __construct(
        private readonly Store&LockProvider $store,
        private readonly string $key = '{metrics}:state',
        private readonly int $cardinalityLimit = 2000,
        private readonly int $lockWait = 1,
    ) {}

    public function merge(iterable $batch): void
    {
        $batch = is_array($batch) ? $batch : iterator_to_array($batch, false);
        if ($batch === []) {
            return;
        }

        $lock = $this->store->lock($this->key.':lock', self::LOCK_TTL);
        if ($lock instanceof Lock) {
            $lock->betweenBlockedAttemptsSleepFor(10);
        }
        $lock->block($this->lockWait, function () use ($batch): void {
            $memory = $this->read();
            $memory->merge($batch);
            $this->store->forever($this->key, $memory->state());
        });
    }

    public function collect(ResourceInfo $resource): array
    {
        return $this->read()->collect($resource);
    }

    public function clear(): void
    {
        $this->store->forget($this->key);
    }

    private function read(): InMemoryStore
    {
        $state = $this->store->get($this->key);

        /** @phpstan-ignore argument.type (the entry holds what state() returned) */
        return new InMemoryStore($this->cardinalityLimit, is_array($state) ? $state : []);
    }
}
