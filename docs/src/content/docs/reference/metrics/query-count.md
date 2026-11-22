---
title: Queries per request and per job
description: The laravel.db.queries histogram, the number of queries one HTTP request or one queued job ran.
---

`Nwidart\Metrics\Instrumentation\DatabaseQueryCountMetrics` records how many successful queries one HTTP request or one queued job ran, on every connection. A route whose requests run many queries often has an N+1: a relation loaded once per model instead of once for all of them.

## Instrument

| Property | Value |
|---|---|
| Name | `laravel.db.queries` |
| Kind | Histogram |
| Unit | `{query}` |
| Buckets | `0, 1, 2, 5, 10, 25, 50, 100, 250, 500, 1000` |
| Description | `Number of database queries run by one HTTP request or one queued job.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `laravel_db_queries` |

The first bucket holds the requests and jobs that ran no query.

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `http.route` | string | The route template, such as `/users/{user}` | An HTTP request that matched a route |
| `laravel.job.name` | string | The job class, or its display name | A queued job |

## Recorded when

| Event | Effect |
|---|---|
| `Illuminate\Database\Events\QueryExecuted` | Adds one to the count |
| `Illuminate\Foundation\Http\Events\RequestHandled` | Records the request's count, and starts again from 0 |
| `Illuminate\Queue\Events\JobProcessing` | Starts again from 0 |
| `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` | Records the job's count |

## Not recorded

- Requests to the routes in [`ignore_routes`](/laravel-metrics/reference/configuration/#ignore_routes).
- Commands. A queue worker is a command whose own queries are not the work of any job.
- A job on the sync queue on its own. Its queries count for the request that dispatched it.
- The queries of a streamed response (`response()->stream()`). They run while the response is sent, after `RequestHandled`.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `laravel.db.queries` receives the `RequestHandled` event for a request, and `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` for a job.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.db` | `METRICS_DB` | `true` |
