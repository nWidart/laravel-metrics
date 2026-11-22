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
| `laravel.cache.operations` (counter) | `laravel.cache.store`, `laravel.cache.operation` (`read`, `write`, `delete`), `laravel.cache.result` (`hit`, `miss`, `success`, `failure`) |

The database listener costs about 2 µs per query. Laravel dispatches no event for a failed query, so failed queries are not recorded.

`messaging.process.duration` has one value per attempt. An attempt that throws has the exception class as `error.type`; a job that fails itself, or has no attempts left, has `error.type` = `failed`. `laravel.job.name` is the job class, which the conventions do not define. A queue worker merges after each job; a sync job merges with the request or command that dispatched it.

The OpenTelemetry semantic conventions define no cache metrics, so `laravel.cache.operations` and its attributes use the `laravel.` namespace. It counts Laravel's cache events, per store: a read is a hit or a miss, and a write or a delete is a success or a failure. The hit ratio is `hit / (hit + miss)`. The cache key is never an attribute. The listener costs about 1.5 µs per cache event, and about 3 µs with Laravel's event dispatch. Limits:

- No duration: on Laravel 12.0, timing an operation would need state per key, and `many` has one start event for several keys.
- Operations that dispatch no event are not counted: `increment`, `decrement`, `flush`, locks, and `add` with a TTL on a store that adds natively (Redis, Memcached, database, DynamoDB, file).
- `many` and `putMany` count one operation per key. `remember` counts a read, then a write on a miss.
- A store with `'events' => false` in `config/cache.php` dispatches no events and is not counted. The memoized store (`Cache::memo()`) is not counted; the store under it is, on a memo miss. A failover store is counted under the name of the store that ran the operation.

## Prometheus

Turn on the scrape route with `METRICS_PROMETHEUS=true`. `GET /metrics` then serves the store in the Prometheus text format (version 0.0.4). The names follow the OpenTelemetry Prometheus compatibility rules: `http.server.request.duration` in seconds becomes `http_server_request_duration_seconds`, and a counter ends in `_total`. Every sample has the `otel_scope_name` label.

The route is off by default, because metrics can tell more about the application than it should share. Protect it with `prometheus.middleware` or with the network. Change the path with `METRICS_PROMETHEUS_PATH`, and add labels to every sample with `prometheus.labels`, for example `['service' => 'shop']`. If the store cannot be read, the route answers 503, so Prometheus marks the target down. The scrape is an HTTP request like the others: it is recorded in `http.server.request.duration` with `http.route` = `/metrics`.

All workers and hosts share one store, so scrape one address, such as the load balancer.

## Production

Run `composer install --optimize-autoloader` (or `composer dump-autoload -o`). Without an optimized classmap, booting the SDK costs about 1 ms per request instead of about 75 µs.

## Configuration

To change the defaults, publish the configuration file:

```bash
php artisan vendor:publish --tag=metrics-config
```
