---
title: How it works
description: Why OpenTelemetry metrics break under PHP-FPM, and how the package records each request as a delta and merges it into a shared store.
---

Laravel Metrics records through the OpenTelemetry SDK, like any other OpenTelemetry application. It adds one part that PHP-FPM lacks: a place where the metrics of every request add up. This page explains the problem, the approach, and what happens in each kind of process.

## The problem: PHP-FPM keeps no state

The OpenTelemetry SDK aggregates metrics in process memory. A long-running server keeps one `MeterProvider` for its whole life, so a counter grows from the start of the process and the exporter sends its cumulative value.

PHP-FPM shares nothing between requests. Every request builds a new application, so it builds a new `MeterProvider` too. A cumulative counter therefore starts again at zero on each request. Each request would send "1 request so far", from many workers at once.

The OpenTelemetry PHP maintainers advise delta temporality for PHP-FPM ([open-telemetry/opentelemetry-php#970](https://github.com/open-telemetry/opentelemetry-php/issues/970)): each request sends only what it recorded. That solves the reset, but it moves the problem downstream:

- Prometheus cannot ingest delta values. It reads cumulative counters and histograms.
- The Collector's `deltatocumulative` processor can turn deltas into cumulative values, but it drops deltas that arrive out of order. Many workers that send at the same moment produce exactly that ([keepsuit/laravel-opentelemetry#105](https://github.com/keepsuit/laravel-opentelemetry/issues/105)).

The missing part is aggregation across processes: something that adds the deltas of every worker on every host, in any order, into one cumulative value.

## The approach: OpenTelemetry at both ends

The package keeps OpenTelemetry where it already works and adds only the aggregation.

```
Request, command or queued job (any worker, any host)
  built-in listeners ─┐
  Metrics::meter() ───┴──► OTel SDK (delta, in memory)
                              │ terminating, or after each queued job
                              ▼
                     StoreExporter: one script call
                              ▼
                     Redis: cumulative series
                              ▲
Export                        │ read only
  php artisan metrics:push ───┤──► OTLP/HTTP endpoint
  GET /metrics ───────────────┘──► Prometheus text format
```

- **Recording side.** Each process records through the OpenTelemetry API, with delta temporality. Recording does no I/O: the SDK aggregates in memory.
- **Merge.** When the work ends, the SDK hands its delta to `StoreExporter`. The exporter merges it into the store with one Redis script call. Addition is order-independent, so the store does not care which worker merges first.
- **Store.** The store holds the cumulative value of every series, for all workers and hosts that share it.
- **Export side.** An export reads the store. `metrics:push` sends the values over OTLP with the stock OpenTelemetry exporter. `GET /metrics` renders them in the Prometheus text format.

The store keeps cumulative values and the export sends cumulative values. A cumulative read is idempotent, which matters for pushes. See [Scrape or push](/laravel-metrics/explanation/scrape-or-push/).

## One request, start to end

1. The request builds the application. The service provider registers its listeners, but builds no meter provider yet.
2. The first measurement, such as a query, builds the meter provider and the instrument that the listener holds. This boot costs about 75 µs with an optimized autoloader.
3. Every query, cache event and the HTTP response add to instruments in memory.
4. Laravel sends the response. Under PHP-FPM, sending calls `fastcgi_finish_request()`, so the client has its response before the next step.
5. Laravel runs its `terminating` callbacks. The package flushes the meter provider there. The flush merges the request's delta into the store in one script call.

A request that records nothing never builds the meter provider, and a flush then does nothing. No Redis call happens.

## Other kinds of process

**Artisan commands** follow the same path: a command terminates, and the package flushes. This includes `schedule:run`, so scheduled task durations merge when the scheduler process ends.

**Queue workers** run many jobs in one long-lived process. The worker keeps one meter provider, and the package flushes after each job: when the job is processed, throws, fails, or times out. A timed-out job is merged before the worker kills itself. Each flush merges only what was recorded since the previous one, so a job costs one merge, like a request. Horizon runs `queue:work`, so the same applies.

**Sync jobs** run inside the request or command that dispatched them. They do not flush on their own: their metrics merge with the request or command when it terminates.

**Laravel Octane** boots the application once per worker and handles each request in a clone of it. The worker keeps one meter provider, built when the worker starts, so a request does not pay the SDK boot. Each request flushes when it terminates, after Octane has sent the response, as under PHP-FPM. Octane tasks and ticks flush when they end, and a stopping worker flushes what is left. Swoole, RoadRunner and FrankenPHP behave the same: a worker handles one request at a time, and workers share nothing but the store.

**Other long-running work**, such as a daemon command, merges only when it ends, unless it calls `Metrics::flush()`. See [Flush in long-running work](/laravel-metrics/guides/flush-long-running-work/).

## Why record through the OpenTelemetry API

The package could have kept its own counters. It records through the OpenTelemetry API instead, for two reasons.

First, interoperability. By default the package's meter provider is also the OpenTelemetry global (`Globals::meterProvider()`). Any library that records through the OpenTelemetry API then feeds the same store, with no extra work. Your own metrics use the same API, so they look like any other OpenTelemetry instrumentation.

Second, correctness that the SDK already provides: instrumentation scopes, units, explicit histogram buckets, start timestamps, and the OTLP data model that the exporter expects.

The cost was the main risk. A spike measured it before the packages were built: with an optimized autoloader, booting the SDK takes about 75 µs per request. Without one, it takes about 1 ms. See [Performance and overhead](/laravel-metrics/explanation/performance/).

## Why Laravel events, not an extension

The built-in instrumentation listens to Laravel's own events: `RequestHandled`, `QueryExecuted`, the queue, cache and scheduler events, and a `reportable()` callback on the exception handler. The goal is that `composer require` alone gives useful metrics. An approach based on `ext-opentelemetry` would need a PHP extension on every server, which many hosts do not allow.

Events have limits. Laravel dispatches no event for a failed query, so failed queries are not recorded. Some cache operations dispatch no event either. The [reference pages](/laravel-metrics/reference/metrics/http/) list each limit.

## Two packages

The work is split into two packages:

- [`nwidart/metrics-store`](https://github.com/nWidart/metrics-store) holds the store contract, the Redis and in-memory stores, `StoreExporter`, and the Prometheus text format. It has no framework dependency, so any PHP application under PHP-FPM can use it.
- `nwidart/laravel-metrics` wires the store into Laravel: the service provider, the configuration, the instrumentation, the `Metrics` facade, the `/metrics` route, `metrics:push`, the `cache` store and the test fake.

The merge rules, such as how a histogram adds up and how the cardinality limit works, belong to metrics-store. Read its [design](https://github.com/nWidart/metrics-store/blob/main/docs/design.md) for the decisions behind the store.
