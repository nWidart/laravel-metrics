---
title: Cache operations
description: The laravel.cache.operations counter, its attributes and the cache events it counts.
---

`Nwidart\Metrics\Instrumentation\CacheMetrics` counts cache operations from Laravel's cache events. The OpenTelemetry semantic conventions define no cache metric, so the metric and its attributes use the `laravel.` namespace.

## Instrument

| Property | Value |
|---|---|
| Name | `laravel.cache.operations` |
| Kind | Counter |
| Unit | `{operation}` |
| Buckets | None |
| Description | `Number of cache operations.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `laravel_cache_operations_total` |

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `laravel.cache.operation` | string | `read`, `write`, `delete` | Always |
| `laravel.cache.result` | string | `hit` or `miss` for a read, `success` or `failure` for a write or a delete | Always |
| `laravel.cache.store` | string | The store name from `config/cache.php`, such as `redis` | The event has a store name |

The cache key is never an attribute.

## Recorded when

| Event | `laravel.cache.operation` | `laravel.cache.result` |
|---|---|---|
| `Illuminate\Cache\Events\CacheHit` | `read` | `hit` |
| `Illuminate\Cache\Events\CacheMissed` | `read` | `miss` |
| `Illuminate\Cache\Events\KeyWritten` | `write` | `success` |
| `Illuminate\Cache\Events\KeyWriteFailed` | `write` | `failure` |
| `Illuminate\Cache\Events\KeyForgotten` | `delete` | `success` |
| `Illuminate\Cache\Events\KeyForgetFailed` | `delete` | `failure` |

Each event adds 1:

- `many()` and `putMany()` add one per key.
- `remember()` adds a read, then a write on a miss.
- A failover store records the name of the store that ran the operation.

## Not recorded

- Operations that dispatch no key event: `increment`, `decrement`, locks, and `add` with a TTL on a store that adds natively (Redis, Memcached, database, DynamoDB, file).
- `flush`. Laravel dispatches flush events, and the package does not listen to them.
- Operations of a store with `'events' => false` in `config/cache.php`.
- Operations of the memoized store (`Cache::memo()`). The store under it is counted, on a memo miss.
- Reads and writes of the [`cache` metrics store](/laravel-metrics/reference/stores/#cache).
- Durations. The metric is a counter only.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `laravel.cache.operations` receives the cache event, an `Illuminate\Cache\Events\CacheEvent`, with `$event->key`, `$event->storeName` and `$event->tags`.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.cache` | `METRICS_CACHE` | `true` |
