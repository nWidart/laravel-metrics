---
title: Metric names
description: How the built-in metrics follow the OpenTelemetry semantic conventions, where they use their own namespaces, and how the names change in Prometheus.
---

A metric name is a contract. Dashboards, alerts and queries depend on it, so a name that changes breaks them. The package picks its names by one rule: follow the OpenTelemetry semantic conventions where they define the metric, and use a clear namespace where they do not.

## Semantic conventions first

The [OpenTelemetry semantic conventions](https://opentelemetry.io/docs/specs/semconv/) define names, units and attributes for common metrics. Following them has two benefits. Dashboards and alerts built for any OpenTelemetry HTTP server work on a Laravel application too. And a backend that knows the conventions can show the data without configuration.

These built-in metrics follow the conventions, with their attributes:

- `http.server.request.duration`
- `db.client.operation.duration`
- `messaging.process.duration` and `messaging.client.sent.messages`
- `system.filesystem.usage`

The conventions also set the attribute values. `db.system.name` is `postgresql`, not Laravel's `pgsql`. `messaging.system` is `aws_sqs`, not Laravel's `sqs`. An unknown HTTP method is `_OTHER`. `error.type` names the error: the exception class, or the status code for an HTTP 5xx response.

## Where the conventions stop

The conventions define no metric for cache operations, transactions, queries per request, Eloquent models, scheduled tasks, reported exceptions, or the PHP runtime. Inventing a name that looks like a convention, such as `cache.operations`, would risk a clash when the conventions add one later. The package uses a namespace instead:

- **`laravel.*`** for what is specific to Laravel: `laravel.cache.operations`, `laravel.db.transaction.duration`, `laravel.db.queries`, `laravel.db.server.connections`, `laravel.db.migrations.pending`, `laravel.eloquent.models`, `laravel.scheduler.task.duration`, `laravel.exceptions` and `laravel.info`. Their attributes use the same namespace, such as `laravel.cache.store`. The database ones keep the connection attributes of the conventions, such as `db.system.name`.
- **`php.*`** for the PHP runtime: `php.opcache.*` and `php.fpm.*`. Other runtimes do the same: the conventions have `jvm.*` for Java and `v8js.*` for Node.js.

Two names come from elsewhere:

- `system.cpu.load_average.1m` is the name that the Collector's host metrics receiver uses. A dashboard that reads host metrics from the Collector reads this one too.
- `laravel.job.name` is an attribute on the queue metrics. The messaging conventions define the queue (`messaging.destination.name`), not what ran. The job class says what ran, so the package adds it in its own namespace.

`exception.type` on `laravel.exceptions` is the attribute from the conventions, even though the metric name is not.

## Units and buckets

Every duration is in seconds (`s`), as the conventions require. Sizes are in bytes (`By`). Counts carry an annotation in braces, such as `{operation}` or `{message}`, which says what is counted but is not a unit.

A histogram needs bucket boundaries, and the store can only merge values with the same boundaries. The package therefore fixes the boundaries of each built-in histogram:

- HTTP requests and queue jobs use the boundaries the conventions advise, from 5 ms to 10 s.
- Queries and transactions use the boundaries the conventions advise for database clients, from 1 ms to 10 s.
- Queries per request or job count queries, not time: from 0 to 1000.
- Scheduled tasks run for up to minutes, so they use wider boundaries, from 0.1 s to 900 s.

The boundaries are part of the metric's identity in the store. A histogram with other boundaries is another metric. This is also why your own histograms need explicit boundaries: the SDK default runs from 0 to 10000, which suits milliseconds, not seconds.

## Names in Prometheus

Prometheus has stricter names than OpenTelemetry, and it puts the unit in the name. The scrape follows the OpenTelemetry Prometheus compatibility rules:

- Characters that Prometheus does not allow, such as dots, become underscores.
- The unit becomes a word at the end: `s` is `_seconds`, `By` is `_bytes`. Annotations such as `{operation}` are dropped.
- A counter ends in `_total`.
- An up-down counter, such as `php.fpm.processes`, is a gauge.
- Every sample has the `otel_scope_name` label, with the meter's name. The built-in metrics use the meter `nwidart/laravel-metrics`. `Metrics::meter()` uses `app` unless you name another.

`http.server.request.duration` therefore appears as `http_server_request_duration_seconds`, with `_bucket`, `_sum` and `_count` samples. `laravel.cache.operations` appears as `laravel_cache_operations_total`. A push over OTLP keeps the OpenTelemetry names; the backend applies its own rules.

## Related

- [Built-in metrics reference](/laravel-metrics/reference/metrics/http/)
- [Prometheus endpoint reference](/laravel-metrics/reference/prometheus-endpoint/)
- [Record your own metrics](/laravel-metrics/guides/record-custom-metrics/)
