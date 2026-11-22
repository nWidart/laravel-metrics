---
title: Exceptions
description: The laravel.exceptions counter, its attribute and which reported exceptions it counts.
---

`Nwidart\Metrics\Instrumentation\ExceptionMetrics` counts the exceptions the application reports. The OpenTelemetry semantic conventions define no exception metric, so the metric uses the `laravel.` namespace. Its attribute comes from the conventions.

## Instrument

| Property | Value |
|---|---|
| Name | `laravel.exceptions` |
| Kind | Counter |
| Unit | `{exception}` |
| Buckets | None |
| Description | `Number of exceptions reported by the application.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `laravel_exceptions_total` |

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `exception.type` | string | The exception class, such as `Illuminate\Database\QueryException` | Always |

For an anonymous class, `exception.type` ends at `@anonymous`, such as `RuntimeException@anonymous`. The file path that PHP adds to the class name is left out.

## Recorded when

The package adds a `reportable()` callback to the exception handler (`Illuminate\Foundation\Exceptions\Handler`). Each call of the callback adds 1.

The callback is added when the exception handler is first resolved, or at boot when it is already resolved. The package does not build the handler itself.

## Not recorded

- Exceptions that Laravel does not report, such as an `HttpException` for a 404, a `ValidationException` or an `AuthenticationException`, and the classes in the handler's `dontReport` list.
- An exception with its own `report()` method.
- An exception that an earlier `reportable()` callback stops.
- Exceptions of an exception handler that does not extend `Illuminate\Foundation\Exceptions\Handler`.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `laravel.exceptions` receives the reported `Throwable`.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.exceptions` | `METRICS_EXCEPTIONS` | `true` |
