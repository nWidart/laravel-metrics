# Laravel Metrics

OpenTelemetry metrics for Laravel that work under PHP-FPM. HTTP requests, queries, queue jobs, cache operations, scheduled tasks and exceptions are recorded with no code; serve them to Prometheus or push them over OTLP.

## Installation

```bash
composer require nwidart/laravel-metrics
```

The service provider registers itself. Recording starts at once, into the Redis `default` connection. Then pick how the metrics leave the application:

- Prometheus scrapes them: set `METRICS_PROMETHEUS=true` and protect `GET /metrics` (see [Prometheus](#prometheus)).
- They are pushed over OTLP: set `OTEL_EXPORTER_OTLP_ENDPOINT` and schedule `metrics:push` (see [OTLP push](#otlp-push)).

Without Redis, for development, set `METRICS_STORE=cache` (see [Stores](#stores)).

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13
- Redis in production. Development can run without it: see [Stores](#stores).

## How it works

The store and the merge rules live in [`nwidart/metrics-store`](https://github.com/nWidart/metrics-store), which has no Laravel dependency; read its [design](https://github.com/nWidart/metrics-store/blob/main/docs/design.md) for the reasons.


- Each request records through the OpenTelemetry SDK, with delta temporality.
- After the response is sent (`terminating`), the request merges its delta into Redis with one script call. A queue worker merges after each job.
- The store keeps the cumulative values for all workers and hosts.
- A Redis failure never fails the request.

Record your own metrics through the OpenTelemetry API:

```php
use Nwidart\Metrics\Facades\Metrics;

Metrics::meter('app')->createCounter('orders.placed', '{order}')->add(1, ['channel' => 'web']);
```

`Metrics::meter()` returns an OpenTelemetry meter of the package's meter provider, which you can also resolve as `OpenTelemetry\API\Metrics\MeterProviderInterface`. The package flushes when a request or a command terminates and after each queued job. Call `Metrics::flush()` in other long-running work.

By default the package's meter provider is also the OpenTelemetry global (`Globals::meterProvider()`), so libraries that use the OpenTelemetry API feed the store too.

## Built-in instrumentation

| Metric | Attributes |
|---|---|
| `http.server.request.duration` (histogram, seconds) | `http.request.method`, `http.route`, `http.response.status_code`, `url.scheme`, `network.protocol.version`, `error.type` |
| `db.client.operation.duration` (histogram, seconds) | `db.system.name`, `db.namespace`, `db.operation.name`, `server.address`, `server.port` |
| `messaging.process.duration` (histogram, seconds) | `messaging.system`, `messaging.operation.name` (`process`), `messaging.destination.name`, `laravel.job.name`, `error.type` |
| `messaging.client.sent.messages` (counter) | `messaging.system`, `messaging.operation.name` (`send`), `messaging.destination.name`, `laravel.job.name` |
| `laravel.scheduler.task.duration` (histogram, seconds) | `laravel.scheduler.task.name`, `error.type` |
| `laravel.exceptions` (counter) | `exception.type` |
| `laravel.cache.operations` (counter) | `laravel.cache.store`, `laravel.cache.operation` (`read`, `write`, `delete`), `laravel.cache.result` (`hit`, `miss`, `success`, `failure`) |

Requests to the routes in `metrics.ignore_routes` are not recorded, so that health probes and scrapes do not drown the application's own traffic. A pattern that starts with `/` matches the URI template (`/up`); any other pattern matches the route name (`horizon.*`).

The database listener costs about 2 µs per query. Laravel dispatches no event for a failed query, so failed queries are not recorded.

`messaging.process.duration` has one value per attempt. An attempt that throws has the exception class as `error.type`; a job that fails itself, or has no attempts left, has `error.type` = `failed`; an attempt that the worker stops at its timeout has `error.type` = `Illuminate\Queue\TimeoutExceededException`, and is merged before the worker exits. `laravel.job.name` is the job class, which the conventions do not define. A queue worker merges after each job; a sync job merges with the request or command that dispatched it.

`laravel.scheduler.task.duration` has one value per run of a scheduled task. `laravel.scheduler.task.name` is the task's description, or its command without arguments (`php artisan report:send --token=secret` is `report:send`), because arguments can hold a secret and change on each run. Give two tasks that run one command a `description()` to tell them apart. `error.type` is the exception class when the task threw, and `failed` when its command exited with another code than 0. A task skipped by `withoutOverlapping()` is not recorded; a background task is timed until it has started.

`laravel.exceptions` counts the exceptions the application reports, by class (`exception.type`). Exceptions Laravel does not report, such as a 404 or a validation failure, are not counted; nor is an exception with its own `report()` method, or one that an earlier `reportable()` callback stops.

The OpenTelemetry semantic conventions define no cache metrics, so `laravel.cache.operations` and its attributes use the `laravel.` namespace. It counts Laravel's cache events, per store: a read is a hit or a miss, and a write or a delete is a success or a failure. The hit ratio is `hit / (hit + miss)`. The cache key is never an attribute. The listener costs about 1.5 µs per cache event, and about 3 µs with Laravel's event dispatch. Limits:

- No duration: on Laravel 12.0, timing an operation would need state per key, and `many` has one start event for several keys.
- Operations that dispatch no event are not counted: `increment`, `decrement`, `flush`, locks, and `add` with a TTL on a store that adds natively (Redis, Memcached, database, DynamoDB, file).
- `many` and `putMany` count one operation per key. `remember` counts a read, then a write on a miss.
- A store with `'events' => false` in `config/cache.php` dispatches no events and is not counted. The memoized store (`Cache::memo()`) is not counted; the store under it is, on a memo miss. A failover store is counted under the name of the store that ran the operation.

### Add attributes to the built-in metrics

Add attributes, such as a tenant or a module, from a service provider. The callback receives what the measurement is about, and its attributes win over built-in ones of the same name:

```php
use Illuminate\Foundation\Http\Events\RequestHandled;
use Nwidart\Metrics\Facades\Metrics;

Metrics::attributes('http.server.request.duration', fn (RequestHandled $event): array => [
    'app.tenant' => $event->request->user()?->tenant->slug ?? 'none',
]);
```

| Metric | The callback receives |
|---|---|
| `http.server.request.duration` | `Illuminate\Foundation\Http\Events\RequestHandled` |
| `db.client.operation.duration` | `Illuminate\Database\Events\QueryExecuted` |
| `messaging.process.duration` | `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` |
| `messaging.client.sent.messages` | `JobQueued` |
| `laravel.cache.operations` | the `Illuminate\Cache\Events\CacheEvent` |
| `laravel.scheduler.task.duration` | `ScheduledTaskFinished` or `ScheduledTaskFailed` |
| `laravel.exceptions` | the `Throwable` |

Each distinct value is a new series, so keep the values few. Overriding a built-in attribute also bounds it: `fn () => ['messaging.destination.name' => 'tenant']` puts every queue under one name when you create queues at runtime. A callback runs inside the work it describes, so nothing it does can fail that work: one that throws is reported once and skipped, and a value that is not a string, a number or a boolean is dropped. The measurements a callback causes itself, such as a query, get no contributed attributes, so a callback never calls itself.

## Metrics read on export

These are read when the metrics are exported (a scrape of `/metrics`, or `metrics:push`) and never stored. They describe the process and the server that export them, not the whole application. Requests pay nothing for them. Turn them off with `METRICS_RUNTIME=false`.

| Metric | Attributes | Present when |
|---|---|---|
| `laravel.info` (gauge, 1) | `laravel.version`, `process.runtime.name`, `process.runtime.version`, `php.sapi` | Always |
| `php.opcache.memory.usage` (bytes) | `php.opcache.memory.state`: `used`, `free`, `wasted` | OPcache runs |
| `php.opcache.scripts` | | OPcache runs |
| `php.opcache.lookups` (counter) | `php.opcache.lookup.result`: `hit`, `miss` | OPcache runs |
| `php.fpm.processes` | `php.fpm.process.state`: `active`, `idle` | Under PHP-FPM |
| `php.fpm.listen_queue` | | Under PHP-FPM |
| `php.fpm.connections.accepted`, `php.fpm.max_children_reached`, `php.fpm.slow_requests` (counters) | | Under PHP-FPM |
| `system.cpu.load_average.1m` | | Not on Windows |
| `system.filesystem.usage` (bytes) | `system.filesystem.state`: `used`, `free` | The disk that holds the application |

The semantic conventions define no PHP runtime metrics, so the PHP ones use a `php.` namespace, like `jvm.*` and `v8js.*`. `system.filesystem.usage` follows the conventions; `system.cpu.load_average.1m` is the name of the Collector's host metrics receiver. OPcache is off on the command line by default, so `metrics:push` usually has no OPcache metrics. There are no metrics for the exporting process itself (CPU time, memory), because each export runs in another short-lived process.

Add your own with an observable instrument that is built at export time:

```php
use Nwidart\Metrics\Facades\Metrics;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

Metrics::onExport(function (MeterProviderInterface $provider): void {
    $provider->getMeter('app')->createObservableGauge('queue.size', '{job}', null, [],
        fn (ObserverInterface $observer) => $observer->observe(Queue::size(), ['queue' => 'default']));
});
```

A callback that throws is reported and skipped.

## What an export sends

The scrape and `metrics:push` both send `Metrics::export()`: the cumulative values of the store and the metrics read on export. While metrics are disabled (`METRICS_ENABLED=false`) an export sends nothing, even if the store still holds older values. Leave metrics out of every export with a filter that returns `true` for them, for example the stored series of a meter you no longer record:

```php
use OpenTelemetry\SDK\Metrics\Data\Metric;

Metrics::exclude(fn (Metric $metric): bool => str_starts_with($metric->name, 'legacy.'));
```

## Prometheus

Turn on the scrape route with `METRICS_PROMETHEUS=true`. `GET /metrics` then serves the store in the Prometheus text format (version 0.0.4). The names follow the OpenTelemetry Prometheus compatibility rules: `http.server.request.duration` in seconds becomes `http_server_request_duration_seconds`, and a counter ends in `_total`. Every sample has the `otel_scope_name` label.

The route is off by default, because metrics can tell more about the application than it should share. Protect it with `prometheus.middleware` or with the network. Change the path with `METRICS_PROMETHEUS_PATH`, and add labels to every sample with `prometheus.labels`, for example `['service' => 'shop']`. If the store cannot be built or read, the route answers 503, so Prometheus marks the target down. A store that cannot be built, such as an unknown `METRICS_STORE`, never fails the application's requests: it is reported, and nothing is recorded. The scrape is an HTTP request like the others: it is recorded in `http.server.request.duration` with `http.route` = `/metrics`.

All workers and hosts share one store, so scrape one address, such as the load balancer.

## OTLP push

`php artisan metrics:push` sends the store to an OTLP/HTTP endpoint, such as an OpenTelemetry Collector or a backend that accepts OTLP. Each run sends the totals so far (cumulative), so a lost or repeated push does no harm. Schedule it in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('metrics:push')->everyMinute();
```

Set the endpoint with `OTEL_EXPORTER_OTLP_ENDPOINT`, for example `http://collector:4318`; `/v1/metrics` is appended. `OTEL_EXPORTER_OTLP_PROTOCOL` is `http/protobuf` (default) or `http/json`. Headers, such as an API key, go in `metrics.push.headers`. These values are read through `config/metrics.php`, so they work with `php artisan config:cache`.

The resource is `service.name` (`OTEL_SERVICE_NAME`, or `APP_NAME`) and `deployment.environment.name` (`APP_ENV`), with any attribute you add in `metrics.push.resource`. It has no host or process attributes, because the store holds the totals of every process and host. The push needs a PSR-18 HTTP client; Guzzle, which Laravel applications usually have, is one.

## Stores

| `METRICS_STORE` | Use it for | Notes |
|---|---|---|
| `redis` (default) | Production | One script call per flush. Writers never wait. Shared by every worker and host that uses the same Redis. Set the connection with `METRICS_REDIS_CONNECTION`. |
| `cache` | Development without Redis | One entry in a Laravel cache store (`METRICS_CACHE_STORE`, default: the default cache store). Each flush takes a lock, reads the entry and writes it back, so writers wait for each other; a flush that waits more than 1 second is dropped and logged. |

With the `cache` store:

- The cache driver decides who shares the numbers: `file` is one server, `database`, `redis` or `memcached` are every server, and `array` is one process only.
- The driver must support locks. Every Laravel driver does, except `apc`.
- `php artisan cache:clear` also removes the metrics. Prometheus reads this as a counter reset.
- The store's own cache reads and writes are not counted in `laravel.cache.operations`. With the `database` cache driver, its queries are recorded in `db.client.operation.duration`.

## Testing

`Metrics::fake()` keeps what the application records in memory, with the rules of the real stores, so a test needs no Redis and sees only its own metrics:

```php
use Nwidart\Metrics\Facades\Metrics;

$metrics = Metrics::fake();

$this->post('/orders', $order);

$metrics->assertValue('orders.placed', 1, ['channel' => 'web']);
$metrics->assertRecorded('http.server.request.duration', ['http.route' => '/orders']);
$metrics->assertNotRecorded('laravel.exceptions');
```

Attributes match every series that has at least those attributes. A value is the sum of the matching series: the value of a counter or a gauge, and the count of a histogram. `$metrics->value()` returns it, and `$metrics->metrics()` returns the OpenTelemetry data. `assertNothingRecorded()` checks that nothing was recorded at all.

## Production

Run `composer install --optimize-autoloader` (or `composer dump-autoload -o`). Without an optimized classmap, booting the SDK costs about 1 ms per request instead of about 75 µs.

## Configuration

To change the defaults, publish the configuration file:

```bash
php artisan vendor:publish --tag=metrics-config
```

## Development

Requirements: PHP 8.2 or later, Composer, and a Redis server for the tests.

```bash
composer install
docker run -d --name laravel-metrics-redis -p 6379:6379 redis:7.4-alpine
composer test
composer lint
composer analyse
```

The tests use Redis database 15. Set `REDIS_HOST` and `REDIS_PORT` to use another server. They use phpredis when `ext-redis` is loaded, and predis otherwise.

