---
title: metrics:push
description: The artisan command that sends the export to an OTLP/HTTP endpoint, with its output and exit codes.
---

```bash
php artisan metrics:push
```

Sends [`Metrics::export()`](/laravel-metrics/reference/facade/#export) to an OTLP/HTTP endpoint. The command takes no argument and no option.

- Class: `Nwidart\Metrics\Console\PushCommand`
- Description: `Send the stored metrics to the OTLP endpoint`
- Registered: when the application runs in the console, also while metrics are disabled.

## What it sends

- The cumulative values of the store, and the [metrics read on export](/laravel-metrics/reference/metrics/export-time/), without the metrics that [`Metrics::exclude()`](/laravel-metrics/reference/facade/#exclude) leaves out.
- Cumulative temporality. Each run sends the totals so far.
- Nothing while metrics are [disabled](/laravel-metrics/reference/configuration/#enabled).

## Resource

The resource holds only the attributes of [`metrics.push.resource`](/laravel-metrics/reference/configuration/#pushresource). By default:

| Attribute | Value |
|---|---|
| `service.name` | `OTEL_SERVICE_NAME`, else `APP_NAME`, else `laravel` |
| `deployment.environment.name` | `APP_ENV` |

An attribute whose value is not scalar, or is an empty string, is left out. No host, process, SDK or telemetry attribute is added.

## Transport

| Setting | Source |
|---|---|
| URL | [`metrics.push.endpoint`](/laravel-metrics/reference/configuration/#pushendpoint), without a trailing `/`, then `/v1/metrics` |
| Content type | `application/x-protobuf`, or `application/json` when [`metrics.push.protocol`](/laravel-metrics/reference/configuration/#pushprotocol) is `http/json` |
| Headers | [`metrics.push.headers`](/laravel-metrics/reference/configuration/#pushheaders) |
| Timeout | [`metrics.push.timeout`](/laravel-metrics/reference/configuration/#pushtimeout), in seconds |
| HTTP client | The first PSR-18 client the OpenTelemetry SDK discovers: Guzzle, Symfony HttpClient, Buzz or the php-http cURL client, then any client `php-http/discovery` finds. Laravel requires Guzzle. A client found only through `php-http/discovery` gets no timeout. |

The exporter is the OpenTelemetry `OpenTelemetry\Contrib\Otlp\MetricExporter`, resolved from the container as `OpenTelemetry\SDK\Metrics\MetricExporterInterface`.

## Output and exit codes

| Case | Output | Exit code |
|---|---|---|
| The endpoint accepts the metrics | `INFO  Sent <n> metrics.` | `0` |
| The export is empty | `INFO  The store holds no metrics.` | `0` |
| The store cannot be built or read | `ERROR  The metrics store cannot be read: <message>` | `1` |
| The endpoint refuses the metrics, or the push throws | `ERROR  The OTLP endpoint did not accept the metrics.` | `1` |
| `metrics.push.endpoint` is missing, and the export is not empty | Uncaught `InvalidArgumentException`: `Set metrics.push.endpoint (OTEL_EXPORTER_OTLP_ENDPOINT) to push metrics.` | `1` |

`<n>` is the number of metrics, not of series. A store failure is passed to `report()`, and so is an exception that the exporter throws. A refused or failed request does not throw: the OTLP exporter logs it through the OpenTelemetry logger (the PHP error log by default) and returns `false`.

## Schedule

```php title="routes/console.php"
use Illuminate\Support\Facades\Schedule;

Schedule::command('metrics:push')->everyMinute()->onOneServer();
```

See [Push over OTLP](/laravel-metrics/guides/push-over-otlp/).
