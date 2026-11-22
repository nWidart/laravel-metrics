---
title: Prometheus endpoint
description: The scrape route, its responses, and how OpenTelemetry metrics become Prometheus samples.
---

The scrape route serves [`Metrics::export()`](/laravel-metrics/reference/facade/#export) in the Prometheus text format.

## Route

| Property | Value |
|---|---|
| Method | `GET` (and `HEAD`) |
| URI | [`metrics.prometheus.path`](/laravel-metrics/reference/configuration/#prometheuspath), `metrics` by default |
| Name | `metrics.prometheus` |
| Middleware | [`metrics.prometheus.middleware`](/laravel-metrics/reference/configuration/#prometheusmiddleware), none by default |
| Controller | `Nwidart\Metrics\Http\PrometheusController` (invokable) |

The package registers the route when both are true:

- [`metrics.enabled`](/laravel-metrics/reference/configuration/#enabled) is `true`;
- [`metrics.prometheus.enabled`](/laravel-metrics/reference/configuration/#prometheusenabled) is `true`.

With cached routes (`php artisan route:cache`), the package registers no route. The cached routes hold the route as it was when the cache was built.

The controller can serve under another route:

```php title="routes/web.php"
use Nwidart\Metrics\Http\PrometheusController;

Route::get('/internal/metrics', PrometheusController::class)->middleware('auth.basic');
```

## Responses

| Case | Status | Content type | Body |
|---|---|---|---|
| Export read | `200` | `text/plain; version=0.0.4; charset=utf-8` | The samples |
| Metrics disabled, with the controller on a route of your own, or with routes cached while metrics were on | `200` | `text/plain; version=0.0.4; charset=utf-8` | Empty |
| The store cannot be built or read | `503` | `text/plain; charset=utf-8` | `The metrics store cannot be read.` |

On a `503`, the exception is passed to `report()`. While metrics are disabled, the package registers no route, so its own path answers `404`.

The resource of the export is empty, so no resource attribute becomes a label.

## Format

The text format is version 0.0.4, rendered by `Nwidart\MetricsStore\Prometheus\PrometheusTextFormat`. It follows the OpenTelemetry Prometheus and OpenMetrics compatibility specification.

### Names

- Characters that Prometheus does not allow become `_`. Repeated `_` become one.
- The unit is added as a word: `s` adds `_seconds`, `By` adds `_bytes`. An annotation such as `{request}` adds nothing. The unit `1` adds `_ratio` to a gauge, and nothing to other types.
- A monotonic sum ends in `_total`, once.
- The `# HELP` line holds the description of the instrument.
- Metric families are sorted by name.

| OpenTelemetry metric | Prometheus metric |
|---|---|
| `http.server.request.duration` (`s`) | `http_server_request_duration_seconds` |
| `laravel.exceptions` (counter) | `laravel_exceptions_total` |
| `system.filesystem.usage` (`By`) | `system_filesystem_usage_bytes` |

### Types

| OpenTelemetry data | Prometheus type |
|---|---|
| Monotonic sum (counter) | `counter` |
| Non-monotonic sum (up-down counter) | `gauge` |
| Gauge | `gauge` |
| Histogram | `histogram`, with cumulative `le` buckets, `_sum` and `_count` |

### Labels

- Each attribute becomes a label. Characters that Prometheus does not allow become `_`. A boolean becomes `true` or `false`.
- `otel_scope_name` holds the name of the meter. For the built-in metrics, it is `nwidart/laravel-metrics`.
- `otel_scope_version` holds the version of the meter, when it has one.
- The labels of [`metrics.prometheus.labels`](/laravel-metrics/reference/configuration/#prometheuslabels) are added to every sample. An attribute of the same name wins over them.
- Labels are sorted by name, with `le` last.

### Left out

- Delta metrics.
- A metric whose name another metric of another type already uses.
- A repeated sample.

## Example

```text
# HELP http_server_request_duration_seconds Duration of HTTP server requests.
# TYPE http_server_request_duration_seconds histogram
http_server_request_duration_seconds_bucket{http_request_method="GET",http_response_status_code="200",http_route="/orders",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https",le="0.005"} 0
...
```

The scrape request is recorded in `http.server.request.duration` like any other request, with `http.route` set to the route URI, unless [`ignore_routes`](/laravel-metrics/reference/configuration/#ignore_routes) matches it.

See [Scrape with Prometheus](/laravel-metrics/guides/scrape-with-prometheus/).
