---
title: Cardinality
description: What a series is, why every distinct attribute value costs, and how the package keeps the number of series bounded.
---

Cardinality is the number of series a metric has. It is the main cost of metrics that you control, and the main way metrics go wrong. This page explains where the cost comes from and how the package keeps it bounded.

## What a series is

A metric records values with attributes. Each distinct set of attribute values is one series. `http.server.request.duration` with three routes, two methods and three status codes can have up to 18 series. Each series is a separate time series in the backend.

The SDK aggregates each series in memory, so the merge does not grow with events. Thirty `SELECT` queries on one connection are one series and one data point in the merge. The store, the export and the backend grow with the number of distinct attribute sets, not with traffic.

## What each series costs

- **Redis memory.** The store keeps one hash per series, for as long as the store lives. A series that never gets traffic again still takes memory until the store is cleared.
- **The first merge.** A new series writes its attributes, its start timestamp and its metric's description. Later merges of the same series only add numbers. A metric whose attribute values change on every request pays the first-merge cost on every request.
- **The export.** An export reads every series, at most 100 per Redis call. More series mean more calls and a longer scrape or push.
- **The backend.** Every series is stored, indexed and queried by Prometheus or your OTLP backend. Most backends charge or slow down per series.

An attribute like a user ID, an order ID, a full URL or a cache key multiplies the series by the number of distinct values. A metric with such an attribute grows without limit.

## The cardinality limit

The store caps each metric at `cardinality_limit` series, 2000 by default, as the OpenTelemetry specification suggests. Attribute sets beyond the limit go to one overflow series with the attribute `otel.metric.overflow = true`. The total stays correct, but the detail of the extra series is lost.

The limit is a safety net, not a budget. A metric that reaches it has an attribute that should not be there. When you see the overflow series in a query, look for the attribute that grows.

## Guards in the built-in instrumentation

The built-in metrics never use a value that a client or the data can make unbounded:

- **HTTP route, not URL.** `http.route` is the route's URI template, such as `/orders/{order}`, not the path that was requested. A request with no route has no `http.route`.
- **Known HTTP methods only.** A method outside the standard methods becomes `_OTHER`, so that a client cannot create series with invented methods.
- **Bounded status codes.** `http.response.status_code` has a small set of values. `error.type` on HTTP is the status code, only for 500 and above.
- **A fixed list of SQL keywords.** `db.operation.name` is the first keyword of the query, only when it is one of a fixed list, such as `SELECT` or `INSERT`. The query text is never an attribute.
- **No cache key.** `laravel.cache.operations` has the store, the operation and the result. The key would create one series per key.
- **No command arguments.** A scheduled task without a description is named after its command without arguments: `php artisan report:send --token=secret` is `report:send`. Arguments can change on each run, and they can hold a secret. Leading variable assignments, such as `TOKEN=secret backup.sh`, are dropped too.
- **Trimmed anonymous classes.** The name of an anonymous exception class holds a file path and a NUL byte. `exception.type` keeps only the part up to `@anonymous`.

Some built-in attributes are bounded by your code, not by the package. `laravel.job.name` has one value per job class, and `messaging.destination.name` has one value per queue. An application that creates queues at runtime, for example one per tenant, makes the second one unbounded.

## Contributed attributes

`Metrics::attributes()` adds your own attributes to the built-in metrics. Each distinct value is a new series, so the same reasoning applies: a tenant slug is fine for tens or hundreds of tenants; a user ID is not.

A contributor can also bound a built-in attribute. Its attributes win over built-in ones of the same name, so a callback that returns `['messaging.destination.name' => 'tenant']` puts every runtime queue under one name. See [Add attributes to built-in metrics](/laravel-metrics/guides/add-attributes/).

## Related

- [Record your own metrics](/laravel-metrics/guides/record-custom-metrics/)
- [Configuration reference](/laravel-metrics/reference/configuration/)
- [Limits](/laravel-metrics/reference/limits/)
