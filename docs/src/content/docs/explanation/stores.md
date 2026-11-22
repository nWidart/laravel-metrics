---
title: About the stores
description: Why Redis is the production store, what the cache store trades away, and how a store's life shapes the numbers that backends see.
---

The store is where the deltas of every request add up. It decides who shares the numbers, how much a merge costs, and when the numbers start again from zero. The package has two stores: `redis` for production and `cache` for development without Redis. The [stores reference](/laravel-metrics/reference/stores/) lists their settings.

## Why Redis

Redis was the first store for practical reasons.

- **Most Laravel applications already run it.** The store needs no new service, only a connection from `config/database.php`.
- **It is shared.** One Redis serves PHP-FPM workers, queue workers and the scheduler, on every host. The store therefore holds the totals of the whole application, not of one server.
- **A merge is one round trip.** The merge is one `EVALSHA` call to a cached Lua script, whatever the number of metrics. `EVAL` runs only when Redis does not have the script yet.
- **Writers never wait.** Redis runs each script atomically, so two workers that merge at the same moment cannot lose each other's values, and neither takes a lock.

The script writes a series' attributes, start timestamp and description only the first time it sees the series. Later merges only add numbers. This keeps the Redis cost of a merge small: about 25 µs of Redis CPU for a light request.

## The cache store

Some developers do not run Redis on their machine. The `cache` store keeps every series in one entry of a Laravel cache store, so that development works with the `file` or `database` cache driver.

The price is the merge. A Laravel cache has no atomic "add to this histogram" operation, so each merge:

1. takes a lock,
2. reads the whole entry,
3. adds the delta,
4. writes the whole entry back.

Writers therefore wait for each other. A merge that waits more than 1 second for the lock is dropped and logged. On one developer machine this does not matter. Under production traffic it would serialize every request on one lock, and the entry grows with every series. This is why the cache store is for development only.

The cache driver decides who shares the numbers:

- `file` is one server.
- `database`, `redis` and `memcached` are every server that uses the same cache.
- `array` is one process only, so its numbers vanish with the request.

The driver must support locks. Every Laravel driver does, except `apc`.

The cache store talks to the raw cache store, not to a cache repository. A repository would dispatch cache events for the store's own reads and writes, and `laravel.cache.operations` would count the metrics store itself. With the `database` cache driver, the store's queries still run through the database connection, so they show up in `db.client.operation.duration`.

## The life of a store

A store keeps a start timestamp for each series. Backends use it to detect resets. As long as the store lives, every series grows from its start.

When the store loses its data, every series starts again. An empty store gives new start timestamps, and backends read this as a counter reset. Rate functions, such as `rate()` in Prometheus, handle a reset correctly. A store loses its data when:

- Redis restarts without persistence, or the keys are deleted.
- Redis evicts a key because it reached `maxmemory`. An evicted key loses part of the data silently, which is worse than a full reset. This is why the store needs a Redis with `maxmemory-policy noeviction`, not a cache Redis that evicts keys.
- `php artisan cache:clear` clears the cache store that holds the metrics, with the `cache` store. It removes the metrics entry with the rest of that cache.

A reset has one more effect when the push goes through an OpenTelemetry Collector. The Collector's Prometheus exporter keeps the last value of a series until `metric_expiration` runs out, five minutes by default. After a reset, series that get no new traffic keep their old value in the Collector for those minutes, next to the series that started again.

## Why there is no APCu store yet

APCu keeps data in the shared memory of one PHP-FPM pool, with no network call. It looks like a fast store for single-server applications. It has one catch: the command line has its own APCu memory, separate from the PHP-FPM pool. `php artisan metrics:push` would read an empty store. An APCu store would need the export to run inside a PHP-FPM request. The design lists APCu for a later version.

## Related

- [Develop without Redis](/laravel-metrics/guides/develop-without-redis/)
- [Set up for production](/laravel-metrics/guides/install-in-production/)
- [Run several applications on one Redis](/laravel-metrics/guides/share-a-redis/)
