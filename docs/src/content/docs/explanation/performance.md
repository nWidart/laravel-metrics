---
title: Performance and overhead
description: Where a request spends time on metrics, why the cost stays small, what was measured, and what moves the numbers.
---

Metrics are worth little if they slow down what they measure. This page explains where the time goes, why the design keeps it small, and what the measurements say. For the steps that lower the cost, see [Reduce the overhead](/laravel-metrics/guides/reduce-overhead/).

## Where the time goes

A request pays for four things:

- **Booting the SDK**, once per request that records: the meter provider, the reader and the instruments.
- **Recording**, in the built-in listeners and in your own code.
- **One merge into the store**, after the response is sent.
- **Nothing for the metrics read on export.** Requests only keep their callbacks.

## Why it is cheap

- **Recording does no I/O.** The listeners add to OpenTelemetry instruments, and the SDK aggregates in process memory.
- **The merge grows with series, not with events.** Thirty `SELECT` queries on one connection are one series and one data point in the merge.
- **One merge per request, after the response.** `terminating` runs after Laravel has sent the response with `fastcgi_finish_request()`, so the client does not wait. A queue worker merges once per job.
- **One round trip.** The merge is one `EVALSHA` call to a cached Lua script, whatever the number of metrics.
- **Small script work.** The script reads a flat argument list, with no JSON decoding in Lua. It writes a series' attributes, start timestamp and description only the first time it sees the series. It sends only non-empty histogram buckets.
- **Nothing recorded, nothing sent.** A request that records nothing makes no Redis call. An ignored route records nothing.
- **Small listeners.** Each listener is a singleton that builds its instrument once. The database listener computes the attributes of each connection once. The exception handler is not built for the package.
- **No resource detection.** The request's meter provider has an empty resource, because the resource detectors cost time on every request. `metrics:push` adds the resource.

## What was measured

There is no benchmark with a full Laravel application yet. The numbers come from three sources, and absolute numbers depend on the machine. Read them as orders of magnitude.

| Work | Cost |
|---|---|
| Boot the SDK (provider, reader, 4 instruments), per request | About 75 µs with an optimized autoloader, about 1 ms without |
| Record 8 values | About 30 µs |
| Database listener, per query | About 2 µs, and about 3 µs with Laravel's event dispatch |
| Cache listener, per cache event | About 1.5 µs, and about 3 µs with Laravel's event dispatch |
| Eloquent listener, per model event | About 1.5 µs with Laravel's event dispatch, under 10% of the time Eloquent takes to hydrate a model from SQLite |
| Merge, PHP worker time, after the response | 0.1 to 0.35 ms |
| Merge, Redis CPU | About 25 µs for 8 values, 60–72 µs for a request with 30 queries |
| Metrics read on export, per request | Nothing |

- The boot, record and merge numbers come from the metrics-store benchmark: PHP 8.4 FPM without Laravel, opcache on, phpredis with a persistent connection, and a local Redis 7.4, in Docker on an Apple M3 Max with 4 CPUs shared by everything.
- The listener numbers come from a loop over the listener in Testbench, PHP 8.4 CLI, without Xdebug.
- The upper merge time comes from Testbench with predis and a local Redis: a request with 30 queries over 8 series, 6 cache counters and 1 HTTP value.

The benchmark also checked correctness under load: 20,000 requests across 8 workers gave exactly 20,000 in Redis and in Prometheus, for every counter and every histogram.

## Latency and saturation

The merge runs after the response, so its cost depends on whether workers have spare capacity. The metrics-store benchmark measured the effect on median response latency, compared with no metrics:

- Boot and record: +0.08 to +0.20 ms, at 1 or 8 concurrent connections.
- With the merge, 1 connection: at most +0.14 ms.
- With the merge, 8 connections on 8 workers, so every worker busy: +0.56 to +0.58 ms.

While workers have spare capacity, the merge barely touches response latency: the client has its response, and the worker merges while it would otherwise wait. Under saturation, the merge acts like extra request time. A worker that is still merging cannot take the next request, so the queue in front of PHP-FPM grows.

## Redis capacity

Redis runs each script on one thread, so Redis CPU per merge sets a ceiling for the whole application, summed over all hosts. At about 25 µs per merge for a light request, one Redis instance caps out near 40,000 merging requests per second. A heavier request, with 30 queries and cache counters, costs 60–72 µs, which puts the ceiling near 15,000 per second.

The merge cost was cut during development. An early script cost 70–80 µs of Redis CPU for 6 data points, because it decoded JSON, wrote every series' attributes on every merge, and compared floats for histogram min and max. The current script reads flat arguments, writes a new series' details once, and keeps no min or max.

## What moves the numbers

- **The autoloader.** Without an optimized classmap, every SDK class that loads checks the file system. Booting then takes about 1 ms instead of 75 µs. This is the largest single factor, and the reason the docs insist on `--optimize-autoloader` in production.
- **Preloading.** With opcache preloading of the OpenTelemetry classes, the boot drops further, to about 40 µs.
- **The Redis connection.** A persistent connection avoids a handshake on every merge. A remote Redis adds a network round trip to every merge.
- **The Redis build.** Float parsing is slower in Alpine (musl) builds of Redis than in glibc builds. Float-heavy merges run about 1.5 times faster on glibc.
- **Cardinality.** A new series costs extra Redis calls on its first merge, and memory for as long as the store lives. See [Cardinality](/laravel-metrics/explanation/cardinality/).
- **The store.** The `cache` store takes a lock and rewrites one entry on every merge, so its writers wait for each other. It is for development only. See [About the stores](/laravel-metrics/explanation/stores/).

## Related

- [Reduce the overhead](/laravel-metrics/guides/reduce-overhead/)
- [metrics-store: performance and overhead](https://github.com/nWidart/metrics-store#performance-and-overhead)
