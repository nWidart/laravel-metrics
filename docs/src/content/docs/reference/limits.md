---
title: Limits
description: The known limits of the package and of its store, grouped by area.
---

## Store

- An evicted Redis key loses data without an error. A Redis with `maxmemory-policy noeviction` evicts no key.
- A cleared or restarted store resets every series. Backends read this as a counter reset.
- Integer counters are exact up to 2^63. A merge that passes this limit fails, and Redis keeps the part of the merge that ran before the error.
- Each metric keeps at most [`cardinality_limit`](/laravel-metrics/reference/configuration/#cardinality_limit) series. Extra attribute sets go to one overflow series.
- A failed merge is not retried. Its delta is lost.
- Two applications with the same Redis database, connection prefix and [`store.prefix`](/laravel-metrics/reference/configuration/#storeprefix) share one store.
- With the `cache` store, writers wait for each other. A merge that waits more than 1 second is dropped. `php artisan cache:clear` on that cache store deletes the metrics store.

## Instruments

- Histograms use explicit bucket boundaries only. Exponential histograms are not supported.
- Histograms keep no min and no max.
- Other bucket boundaries for the same histogram name make another metric.
- Observable instruments created in a request are not merged. Only [`Metrics::onExport()`](/laravel-metrics/reference/facade/#onexport) callbacks read observable values.

## Export

- Two exporters with clocks out of step can send an older timestamp after a newer one. The Prometheus OTLP receiver rejects out-of-order samples.
- The OpenTelemetry Collector's Prometheus exporter drops a series after `metric_expiration` (5 minutes by default) without a push.
- The [metrics read on export](/laravel-metrics/reference/metrics/export-time/) describe only the process and the server that run the export.
- With cached routes, a change to `METRICS_PROMETHEUS` or `METRICS_PROMETHEUS_PATH` takes effect only after `php artisan config:cache`, then `php artisan route:cache`.

## Instrumentation

- Failed queries are not recorded: Laravel dispatches no event for them.
- Cache operations have no duration.
- Cache operations that dispatch no event are not counted. See [Cache operations](/laravel-metrics/reference/metrics/cache/).
- Exceptions that Laravel does not report are not counted. See [Exceptions](/laravel-metrics/reference/metrics/exceptions/).
- A scheduled task that runs in the background is timed until it has started.
- A long-running command merges only when it terminates, unless it calls [`Metrics::flush()`](/laravel-metrics/reference/facade/#flush).

## Runtimes

- Under Laravel Octane, the tests run requests, tasks and ticks through Octane's worker in the test process. CI does not start a Swoole, RoadRunner or FrankenPHP server.
- Under Laravel Octane, call `Metrics::attributes()`, `Metrics::exclude()` and `Metrics::onExport()` only in the `boot()` method of a service provider. The worker keeps every callback, so a call made during each request adds one callback per request, without end.
- Redis Cluster is not tested. A phpredis cluster connection is not supported: the merge fails and logs a warning.
