---
title: Develop without Redis
description: Keep the metrics of a local application in a Laravel cache store, so development needs no Redis server.
---

To record metrics on a machine without Redis, use the `cache` store. It keeps every series in one entry of a Laravel cache store.

## Switch to the cache store

1. Set the store in `.env`:

   ```bash title=".env"
   METRICS_STORE=cache
   ```

2. Pick the cache store that holds the entry. Without `METRICS_CACHE_STORE`, the package uses the default cache store.

   ```bash title=".env"
   METRICS_CACHE_STORE=file
   ```

3. If the configuration is cached, rebuild it:

   ```bash
   php artisan config:cache
   ```

## Pick a cache driver

The cache driver decides which processes share the numbers:

| Driver | Shared by |
|---|---|
| `file` | Every process on this machine |
| `database`, `redis`, `memcached` | Every machine that uses the same cache |
| `array` | One process only |

Avoid `array` for development: each request starts with an empty store, so `/metrics` and `metrics:push` show nothing. It suits tests. See [Test your metrics](/laravel-metrics/guides/test-metrics/).

The driver must support locks. Every Laravel driver does, except `apc`. With `apc`, the store cannot be built: requests record nothing and `/metrics` answers 503.

With the `database` driver, the store's own queries appear in `db.client.operation.duration`.

## Reset the numbers

To start again from zero, clear the cache store that holds the metrics:

```bash
php artisan cache:clear
```

This clears the default cache store. If `METRICS_CACHE_STORE` names another store, pass its name as an argument, such as `php artisan cache:clear file`. The rest of that cache goes too. Prometheus reads it as a counter reset.

## With Laravel Sail

Sail ships a Redis container. To use it, keep the default `redis` store and set `REDIS_HOST=redis` as Sail does. The cache store is then not needed.

## Related

- [Stores reference](/laravel-metrics/reference/stores/)
- [Set up for production](/laravel-metrics/guides/install-in-production/)
- [About the stores](/laravel-metrics/explanation/stores/)
