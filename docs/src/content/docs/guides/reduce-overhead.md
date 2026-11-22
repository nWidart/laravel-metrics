---
title: Reduce the overhead
description: Cut what each request pays for metrics, from booting the SDK to the merge after the response.
---

Each request that records pays to boot the SDK, to record, and to merge into the store after the response. Work through this list, highest effect first.

## 1. Use an optimized autoloader

Without it, booting the SDK costs about 1 ms per request instead of about 75 µs. In the deployment:

```bash
composer install --optimize-autoloader --no-dev
```

Or, after a plain install:

```bash
composer dump-autoload --optimize
```

## 2. Keep the Redis connection open

A new connection per request adds a handshake to every merge. Set `persistent` on the store's connection:

```php title="config/database.php"
'metrics' => [
    'host' => env('REDIS_METRICS_HOST', '127.0.0.1'),
    'port' => env('REDIS_METRICS_PORT', '6379'),
    'database' => env('REDIS_METRICS_DB', '0'),
    'persistent' => true,
],
```

Prefer phpredis (`REDIS_CLIENT=phpredis`) to predis.

## 3. Keep Redis close

A remote Redis adds a network round trip to every merge. Run it in the same network, or the same availability zone, as the application servers.

If you run Redis yourself, prefer a glibc build to an Alpine (musl) build. Float-heavy merges run about 1.5 times faster on glibc.

## 4. Stop recording what you do not read

- Ignore the routes that probes and scrapers call often. See [Ignore routes](/laravel-metrics/guides/ignore-routes/).
- Turn off the instrumentation you do not use, such as `METRICS_CACHE=false`. A busy cache dispatches many events per request. See [Turn off instrumentation](/laravel-metrics/guides/turn-off-instrumentation/).

## 5. Keep attribute values few

Each new series costs Redis calls on its first merge, and memory for as long as the store lives. Check your own metrics and your `Metrics::attributes()` callbacks for values such as IDs or URLs. See [Cardinality](/laravel-metrics/explanation/cardinality/).

## 6. Keep slow reads out of requests

A value that you read from another system, such as a queue size, costs nothing per request when you read it at export time. See [Read values at export time](/laravel-metrics/guides/read-at-export-time/).

## 7. Use the cache store for development only

The `cache` store takes a lock on each merge, so writers wait for each other. Use the `redis` store in production.

## 8. Preload the SDK

To cut the boot further, to about 40 µs, preload the OpenTelemetry classes with OPcache (`opcache.preload`). Preloading needs a server restart after each deployment.

## Related

- [Performance and overhead](/laravel-metrics/explanation/performance/)
- [Set up for production](/laravel-metrics/guides/install-in-production/)
- [Limits](/laravel-metrics/reference/limits/)
