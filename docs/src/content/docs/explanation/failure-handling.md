---
title: Failure handling
description: Why metrics never fail the work they describe, and what the package does instead when Redis, a store, a callback or an export fails.
---

The package follows one rule: metrics never fail the work they describe. A request, a job or a scheduled task that would succeed without metrics succeeds with them. When something in the metrics path fails, the package loses metrics, reports the failure, and lets the work go on.

The rule has a cost. Lost metrics are lost; the package does not retry, buffer or queue them. The alternative would put the application's reliability in the hands of its monitoring, which is the wrong way round.

## Redis is down

The merge runs after the response is sent, in the `terminating` phase. If Redis cannot be reached at that point, `StoreExporter` catches the error and logs a warning through the OpenTelemetry logger, which writes to the PHP error log by default. The request has already succeeded. The metrics of that merge are lost.

The Redis connection is opened on the first merge, not when the store is built. An unreachable Redis therefore fails the merge, never the boot of the request.

The merge is not retried. A retry would hold the worker longer, at the moment Redis is already in trouble, and every worker would do the same. The next request merges its own delta as usual once Redis is back. Because the store is cumulative, the totals then go on growing from where they were, minus the lost merges.

With the `cache` store, a merge that waits more than 1 second for the lock is dropped and logged in the same way.

## The store cannot be built

A store that cannot be built is a configuration error, such as an unknown `METRICS_STORE`, or a cache store that does not support locks. The package does not let it fail requests either.

The first measurement asks for the meter provider. If building the store throws, the package binds a no-op meter provider, reports the exception once, and records nothing in that process. The no-op provider is bound before the exception is reported, because reporting it counts in `laravel.exceptions`, which asks for the meter provider again.

The export side treats the same error differently, on purpose. A scrape answers 503, so that Prometheus marks the target down. `metrics:push` prints an error and exits with 1. An empty answer would look like an application with no traffic, and nobody would notice the broken store.

## Attribute callbacks fail

Callbacks given to `Metrics::attributes()` are application code that runs inside the work it describes, on every measurement of the metric. They are guarded:

- A callback that throws is skipped. Only its first failure in a request, or in a queue worker process, is reported, so a broken callback does not flood the log on every query.
- A value that is not a string, a number or a boolean is dropped.
- While a callback runs, the measurements it causes, such as a query or a cache read, get no contributed attributes. A callback that queries the database therefore never calls itself.

## Export callbacks fail

Callbacks given to `Metrics::onExport()` run during the export. A callback that throws is reported and skipped. The other metrics are still worth an export, so one broken callback does not empty the scrape.

## The push fails

`metrics:push` reports its outcome through its exit code, so that the scheduler and monitoring can see it:

- It exits with 0 when the endpoint accepts the push, or when there is nothing to send.
- It exits with 1 when the store cannot be read or the endpoint refuses the push. A store error is reported through the exception handler. A refused push is logged by the OTLP exporter, through the OpenTelemetry logger.
- A missing endpoint throws, unless there is nothing to send. A missing endpoint is a configuration error that should be loud.

A failed push loses nothing for good. The next push sends the cumulative totals, which include what the failed one would have sent.

## Jobs that time out

A queue worker kills itself right after a job reaches its timeout. The package listens to `JobTimedOut` and merges before the worker exits, so the timed-out attempt is recorded with `error.type` = `Illuminate\Queue\TimeoutExceededException`. Without that, the metrics of the attempt, and of everything since the previous merge, would die with the worker.

## Related

- [Troubleshoot missing metrics](/laravel-metrics/guides/troubleshoot/)
- [Limits](/laravel-metrics/reference/limits/)
