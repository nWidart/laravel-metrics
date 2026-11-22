---
title: Stores
description: The redis and cache store drivers, their keys, their merge rules and their failure behavior.
---

The store holds the cumulative values of every series. Each flush merges a delta into it. Each export reads it. [`store.driver`](/laravel-metrics/reference/configuration/#storedriver) selects the driver.

| Driver | Class | Shared by |
|---|---|---|
| `redis` | `Nwidart\MetricsStore\RedisStore` | Every process and host that uses the same Redis database, connection prefix and store prefix |
| `cache` | `Nwidart\Metrics\Store\CacheStore` | Every process that uses the same cache store and store prefix |

Both implement `Nwidart\MetricsStore\MetricStore`, bound as a singleton in the container.

## Merge rules

Both drivers follow the rules of metrics-store:

- A sum adds the deltas.
- A histogram adds its bucket counts and its sum. Min and max are not kept.
- A gauge keeps the value with the newest timestamp.
- Each series keeps its start timestamp. An empty store gives new start timestamps.
- Each metric keeps at most [`cardinality_limit`](/laravel-metrics/reference/configuration/#cardinality_limit) series. Extra attribute sets go to one series with the attribute `otel.metric.overflow = true`.
- A metric is identified by its instrumentation scope, name, unit, kind and histogram bucket boundaries.

Observable instruments of the requests are not merged. See [Metrics read on export](/laravel-metrics/reference/metrics/export-time/).

## `redis`

| Property | Value |
|---|---|
| Connection | [`store.connection`](/laravel-metrics/reference/configuration/#storeconnection), from `config/database.php` |
| Clients | phpredis (`ext-redis`) or predis |
| Key prefix | The connection's own prefix (`REDIS_PREFIX`), then [`store.prefix`](/laravel-metrics/reference/configuration/#storeprefix) |
| Merge | One Lua script call per flush |
| Export read | One metric at a time, at most 100 series per Redis call |
| Data | One hash per series, one set per metric, one hash of metric descriptions (`<prefix>meta`) |

The connection opens on the first merge or read, not when the store is built.

A connection whose client is neither phpredis `Redis` nor a predis client throws `RuntimeException` on first use:

```text
The metrics store needs a phpredis or predis connection, got <type>. Redis Cluster through phpredis is not supported.
```

## `cache`

| Property | Value |
|---|---|
| Cache store | [`store.cache_store`](/laravel-metrics/reference/configuration/#storecache_store), or the default cache store |
| Cache key | `<store.prefix>state`, `{metrics}:state` by default |
| Lock key | `<cache key>:lock` |
| Lock lifetime | 10 seconds |
| Lock wait | 1 second, with an attempt every 10 ms |
| Entry lifetime | Forever (`Store::forever()`) |
| Merge | Take the lock, read the entry, add the delta, write the entry back |
| Merge engine | `Nwidart\MetricsStore\InMemoryStore` |

- The driver talks to the raw cache store, not to a cache repository. Its reads and writes dispatch no cache events and are not counted in [`laravel.cache.operations`](/laravel-metrics/reference/metrics/cache/).
- A merge that waits more than 1 second for the lock throws `Illuminate\Contracts\Cache\LockTimeoutException`. The flush logs it as a warning, and that delta is lost.
- `clear()` deletes the cache entry. `php artisan cache:clear` deletes it too, when `METRICS_CACHE_STORE` is the default cache store. For another store, pass its name: `php artisan cache:clear file`.

The cache store must support locks. A store that does not throws `InvalidArgumentException` when the store is built:

```text
The metrics cache store must support locks (every Laravel cache driver except apc does).
```

Who shares the values depends on the cache driver:

| Cache driver | Shared by |
|---|---|
| `array` | One process |
| `file` | One server |
| `database`, `redis`, `memcached`, `dynamodb` | Every server that uses the same backend |

## Failures

- A merge that fails is logged as a warning through the OpenTelemetry logger (the PHP error log by default), with the message `Could not merge metrics into the store`. It is not retried. The request, command or job goes on.
- A store that cannot be built, such as an unknown driver, is passed to `report()` once. The process then records nothing.
- An export that cannot build or read the store throws. The [Prometheus endpoint](/laravel-metrics/reference/prometheus-endpoint/) answers `503`, and [`metrics:push`](/laravel-metrics/reference/metrics-push/) exits with `1`.

See [About the stores](/laravel-metrics/explanation/stores/) and [Failure handling](/laravel-metrics/explanation/failure-handling/).
