---
title: Push over OTLP
description: Send the metrics to an OpenTelemetry Collector or an OTLP backend on a schedule, with php artisan metrics:push.
---

To send the metrics to an OTLP/HTTP endpoint, set the endpoint and schedule `metrics:push`.

## 1. Set the endpoint

Set the base URL of the endpoint. The package appends `/v1/metrics`, so leave it out.

```bash title=".env"
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
```

To send JSON instead of protobuf:

```bash title=".env"
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

The package reads these through `config/metrics.php`. After a change, run `php artisan config:cache` again if the configuration is cached.

## 2. Push once by hand

```bash
php artisan metrics:push
```

The command prints `Sent N metrics.` on success. It prints `The store holds no metrics.` when there is nothing to send yet. Exit code 1 means the store cannot be read or the endpoint refused the push. See [Troubleshoot](/laravel-metrics/guides/troubleshoot/).

## 3. Schedule the command

```php title="routes/console.php"
use Illuminate\Support\Facades\Schedule;

Schedule::command('metrics:push')->everyMinute();
```

When several servers run the scheduler, push from one only:

```php title="routes/console.php"
Schedule::command('metrics:push')->everyMinute()->onOneServer();
```

`onOneServer()` needs a cache store that every server shares, such as Redis or the database.

Push more often than the backend forgets a series. The Collector's Prometheus exporter drops a series after `metric_expiration`, 5 minutes by default, without a push.

## Send to an OpenTelemetry Collector

A minimal Collector configuration that accepts the push:

```yaml title="otel-collector.yaml"
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318

exporters:
  debug:
    verbosity: basic

service:
  pipelines:
    metrics:
      receivers: [otlp]
      exporters: [debug]
```

Replace the `debug` exporter with the exporter of your backend.

## Send to a backend that needs an API key

Put the headers in `push.headers`. Publish the configuration first:

```bash
php artisan vendor:publish --tag=metrics-config
```

For a backend that takes a bearer token:

```php title="config/metrics.php"
'push' => [
    // ...
    'headers' => [
        'Authorization' => 'Bearer '.env('OTLP_TOKEN'),
    ],
],
```

For Grafana Cloud, the endpoint is the OTLP gateway of your stack, and the header is Basic authentication with the instance ID and an access policy token:

```bash title=".env"
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp-gateway-prod-eu-west-2.grafana.net/otlp
GRAFANA_INSTANCE_ID=123456
GRAFANA_OTLP_TOKEN=glc_...
```

```php title="config/metrics.php"
'headers' => [
    'Authorization' => 'Basic '.base64_encode(env('GRAFANA_INSTANCE_ID').':'.env('GRAFANA_OTLP_TOKEN')),
],
```

Copy the gateway URL and the instance ID from the OpenTelemetry tile of your Grafana Cloud stack. The region in the URL differs per stack.

Only string header values are sent.

## Set the resource

Each push carries `service.name` and `deployment.environment.name`:

- `service.name` is `OTEL_SERVICE_NAME`, else `APP_NAME`, else `laravel`.
- `deployment.environment.name` is `APP_ENV`.

To add your own attributes:

```php title="config/metrics.php"
'resource' => [
    'service.name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel')),
    'deployment.environment.name' => env('APP_ENV'),
    'service.namespace' => 'shop',
],
```

Do not add host or process attributes. The store holds the totals of every process and host.

## Change the timeout

The push times out after 10 seconds. To change it:

```php title="config/metrics.php"
'timeout' => 5,
```

## Related

- [metrics:push reference](/laravel-metrics/reference/metrics-push/)
- [Configuration reference](/laravel-metrics/reference/configuration/)
- [Scrape or push](/laravel-metrics/explanation/scrape-or-push/)
