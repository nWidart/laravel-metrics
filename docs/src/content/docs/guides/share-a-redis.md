---
title: Run several applications on one Redis
description: Keep the metrics of each application apart in a shared Redis, and make the processes of one application share theirs.
---

The store keys depend on three things: the Redis database, the connection's key prefix (`REDIS_PREFIX`), and `METRICS_PREFIX`. Processes that share all three share their metrics. Processes that differ in one of them do not.

## Keep two applications apart

Give each application its own `METRICS_PREFIX`:

```bash title="shop/.env"
METRICS_PREFIX={shop-metrics}:
```

```bash title="billing/.env"
METRICS_PREFIX={billing-metrics}:
```

Keep the braces. They are a Redis Cluster hash tag that keeps every key of one store in one slot.

Another Redis database, or another `REDIS_PREFIX`, also keeps them apart:

```bash title="billing/.env"
REDIS_METRICS_DB=3
```

`REDIS_METRICS_DB` here is the variable of your own `metrics` connection in `config/database.php`. See [Set up for production](/laravel-metrics/guides/install-in-production/).

## Make the parts of one application share

Web servers, queue workers and the scheduler of one application must write to one store. Give them the same:

- Redis server and database (`METRICS_REDIS_CONNECTION` and its settings);
- `REDIS_PREFIX`, if the connection sets one;
- `METRICS_PREFIX`.

This matters when the parts run from separate deployments or containers, each with its own `.env`.

## Check where a process writes

Print the settings a process uses:

```bash
php artisan tinker --execute="dump(config('metrics.store'), config('database.redis.options.prefix'))"
```

Run it with the environment of each part and compare.

## Change the prefix of a running application

A new prefix starts an empty store. Backends read this as a counter reset. The old keys stay in Redis until you delete them.

## Related

- [Stores reference](/laravel-metrics/reference/stores/)
- [Configuration reference](/laravel-metrics/reference/configuration/)
- [About the stores](/laravel-metrics/explanation/stores/)
