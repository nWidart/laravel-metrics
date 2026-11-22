# Laravel Metrics

OpenTelemetry metrics for Laravel that work under PHP-FPM.

> **Status:** early development. Not usable yet.

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13
- Redis

## How it works

- Each request records through the OpenTelemetry SDK, with delta temporality.
- After the response is sent (`terminating`), the request merges its delta into Redis with one script call. A queue worker merges after each job.
- The store keeps the cumulative values for all workers and hosts.
- A Redis failure never fails the request.

Record your own metrics through the OpenTelemetry API:

```php
use OpenTelemetry\API\Metrics\MeterProviderInterface;

app(MeterProviderInterface::class)->getMeter('app')->createCounter('orders.placed')->add(1);
```

By default the package's meter provider is also the OpenTelemetry global (`Globals::meterProvider()`), so libraries that use the OpenTelemetry API feed the store too.

## Built-in instrumentation

| Metric | Attributes |
|---|---|
| `http.server.request.duration` (histogram, seconds) | `http.request.method`, `http.route`, `http.response.status_code`, `url.scheme`, `network.protocol.version`, `error.type` |
| `db.client.operation.duration` (histogram, seconds) | `db.system.name`, `db.namespace`, `db.operation.name`, `server.address`, `server.port` |
| `messaging.process.duration` (histogram, seconds) | `messaging.system`, `messaging.operation.name` (`process`), `messaging.destination.name`, `laravel.job.name`, `error.type` |
| `messaging.client.sent.messages` (counter) | `messaging.system`, `messaging.operation.name` (`send`), `messaging.destination.name`, `laravel.job.name` |

The database listener costs about 2 µs per query. Laravel dispatches no event for a failed query, so failed queries are not recorded.

`messaging.process.duration` has one value per attempt. An attempt that throws has the exception class as `error.type`; a job that fails itself, or has no attempts left, has `error.type` = `failed`. `laravel.job.name` is the job class, which the conventions do not define. A queue worker merges after each job; a sync job merges with the request or command that dispatched it.

## Production

Run `composer install --optimize-autoloader` (or `composer dump-autoload -o`). Without an optimized classmap, booting the SDK costs about 1 ms per request instead of about 75 µs.

## Configuration

To change the defaults, publish the configuration file:

```bash
php artisan vendor:publish --tag=metrics-config
```
