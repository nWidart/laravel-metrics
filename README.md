# Laravel Metrics

[![CI](https://github.com/nWidart/laravel-metrics/actions/workflows/ci.yml/badge.svg)](https://github.com/nWidart/laravel-metrics/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/nwidart/laravel-metrics)](https://packagist.org/packages/nwidart/laravel-metrics)
[![License](https://img.shields.io/packagist/l/nwidart/laravel-metrics)](LICENSE.md)

OpenTelemetry metrics for Laravel that work under PHP-FPM.

The package records HTTP requests, queries, transactions, Eloquent models, queue jobs, cache operations, scheduled tasks and exceptions with no code. Each request records in memory and merges into Redis after the response. Serve the metrics to Prometheus, or push them over OTLP.

**Documentation: https://nwidart.github.io/laravel-metrics/**. It has a tutorial, how-to guides, the full reference and explanations. This README is a summary.

- [How it works](#how-it-works)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Built-in instrumentation](#built-in-instrumentation)
- [Stores](#stores)
- [Export](#export)
- [Testing](#testing)
- [Performance and overhead](#performance-and-overhead)
- [Operations and limits](#operations-and-limits)
- [Development](#development)

## How it works

The store and the merge rules live in [`nwidart/metrics-store`](https://github.com/nWidart/metrics-store), which has no Laravel dependency. Read its [design](https://github.com/nWidart/metrics-store/blob/main/docs/design.md) for the reasons.

```
Request, command or queued job (any worker, any host)
  built-in listeners ─┐
  Metrics::meter() ───┴──► OTel SDK (delta, in memory)
                              │ terminating, or after each queued job
                              ▼
                     StoreExporter: one script call
                              ▼
                     Redis: cumulative series
                              ▲
Export                        │ read only
  php artisan metrics:push ───┤──► OTLP/HTTP endpoint
  GET /metrics ───────────────┘──► Prometheus text format
```

- Each request records through the OpenTelemetry SDK, with delta temporality.
- After the response is sent (`terminating`), the request merges its delta into Redis with one script call. A queue worker merges after each job.
- The store keeps the cumulative values for all workers and hosts.
- An export reads the store. It sends the values over OTLP, or serves them to Prometheus.
- A Redis failure never fails the request.

## Installation

```bash
composer require nwidart/laravel-metrics
```

The service provider registers itself. Recording starts at once, into the Redis `default` connection. Then pick how the metrics leave the application:

- Prometheus scrapes them: set `METRICS_PROMETHEUS=true` and protect `GET /metrics`. See [Prometheus](#prometheus).
- They are pushed over OTLP: set `OTEL_EXPORTER_OTLP_ENDPOINT` and schedule `metrics:push`. See [OTLP push](#otlp-push).

Without Redis, for development, set `METRICS_STORE=cache`. See [Stores](#stores).

In production, install with an optimized autoloader. Without it, booting the SDK costs about 1 ms per request instead of about 75 µs:

```bash
composer install --optimize-autoloader --no-dev
```

Requirements:

- PHP 8.2 or later
- Laravel 12 or 13
- Redis in production, with phpredis or predis. Development can run without it: see [Stores](#stores).
- For `metrics:push`: a PSR-18 HTTP client. Guzzle, which Laravel applications usually have, is one.

## Configuration

To change the defaults, publish the configuration file to `config/metrics.php`:

```bash
php artisan vendor:publish --tag=metrics-config
```

| Variable | Key | Default | Effect |
|---|---|---|---|
| `METRICS_ENABLED` | `enabled` | `true` | `false` records nothing, registers no listener and no route, and makes every export empty. |
| `METRICS_STORE` | `store.driver` | `redis` | `redis` or `cache`. See [Stores](#stores). |
| `METRICS_REDIS_CONNECTION` | `store.connection` | `default` | The Redis connection from `config/database.php`. |
| `METRICS_PREFIX` | `store.prefix` | `{metrics}:` | The key prefix of the store, after the connection's own prefix. |
| `METRICS_CACHE_STORE` | `store.cache_store` | The default cache store | The cache store of the `cache` driver. |
| `METRICS_REGISTER_GLOBAL` | `register_global` | `true` | Makes the package's meter provider the OpenTelemetry global. |
| `METRICS_PROMETHEUS` | `prometheus.enabled` | `false` | Serves the scrape route. |
| `METRICS_PROMETHEUS_PATH` | `prometheus.path` | `metrics` | The path of the scrape route. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `push.endpoint` | None | The endpoint of `metrics:push`. `/v1/metrics` is appended. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `push.protocol` | `http/protobuf` | `http/protobuf` or `http/json`. |
| `OTEL_SERVICE_NAME` | `push.resource.service.name` | `APP_NAME`, else `laravel` | The `service.name` resource attribute. |
| `APP_ENV` | `push.resource.deployment.environment.name` | None | The `deployment.environment.name` resource attribute. |
| `METRICS_HTTP`, `METRICS_DB`, `METRICS_ELOQUENT`, `METRICS_QUEUE`, `METRICS_CACHE`, `METRICS_SCHEDULER`, `METRICS_EXCEPTIONS`, `METRICS_RUNTIME`, `METRICS_DB_SERVER`, `METRICS_MIGRATIONS` | `instrumentation.*` | `true` | Turns one built-in instrumentation off. |

These keys have no variable:

| Key | Default | Effect |
|---|---|---|
| `cardinality_limit` | `2000` | The maximum number of series per metric. Extra attribute sets go to one overflow series. |
| `ignore_routes` | `[]` | Routes that `http.server.request.duration` leaves out. See [Ignore routes](#ignore-routes). |
| `prometheus.middleware` | `[]` | Middleware of the scrape route: aliases or class names. |
| `prometheus.labels` | `[]` | Labels added to every sample, for example `['service' => 'shop']`. |
| `push.headers` | `[]` | Headers of each push, such as an API key. |
| `push.timeout` | `10` | The push timeout, in seconds. |
| `push.resource` | `service.name`, `deployment.environment.name` | Resource attributes of each push. Add your own here. |

The package reads every value through `config/metrics.php`, so it works with `php artisan config:cache`.

## Usage

### Record your own metrics

Record through the OpenTelemetry API:

```php
use Nwidart\Metrics\Facades\Metrics;

Metrics::meter('app')->createCounter('orders.placed', '{order}')->add(1, ['channel' => 'web']);

Metrics::meter('app')->createHistogram('checkout.duration', 's', null, ['ExplicitBucketBoundaries' => [0.1, 0.5, 1, 5]])
    ->record(0.42);
```

`Metrics::meter()` returns an OpenTelemetry meter of the package's meter provider. You can also resolve the provider as `OpenTelemetry\API\Metrics\MeterProviderInterface`.

Give each histogram its bucket boundaries. The SDK's default boundaries run from 0 to 10000, which suits milliseconds, not seconds. Keep the boundaries of a histogram fixed: other boundaries make another metric.

By default the package's meter provider is also the OpenTelemetry global (`Globals::meterProvider()`). Libraries that use the OpenTelemetry API then feed the store too. Set `METRICS_REGISTER_GLOBAL=false` to turn this off.

### Flush in long-running work

The package flushes when a request or a command terminates, and after each queued job. Call `Metrics::flush()` in other long-running work, such as a daemon command:

```php
Metrics::flush();
```

A flush merges what was recorded since the previous one. A failure never reaches the caller.

### Add attributes to the built-in metrics

Add attributes, such as a tenant or a module, from a service provider. The callback receives what the measurement is about. Its attributes win over built-in ones of the same name:

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
| `laravel.db.transaction.duration` | `TransactionCommitted` or `TransactionRolledBack` |
| `laravel.db.queries` | `RequestHandled`, `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` |
| `laravel.eloquent.models.pruned` | `Illuminate\Database\Events\ModelsPruned` |
| `messaging.process.duration` | `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` |
| `messaging.client.sent.messages` | `JobQueued` |
| `laravel.cache.operations` | The `Illuminate\Cache\Events\CacheEvent` |
| `laravel.scheduler.task.duration` | `ScheduledTaskFinished` or `ScheduledTaskFailed` |
| `laravel.exceptions` | The `Throwable` |

`laravel.eloquent.models` takes no attributes: its models are added up long after their events.

Each distinct value is a new series, so keep the values few. Overriding a built-in attribute also bounds it: `fn () => ['messaging.destination.name' => 'tenant']` puts every queue under one name when you create queues at runtime.

A callback runs inside the work it describes, so nothing it does can fail that work:

- A callback that throws is reported once and skipped.
- A value that is not a string, a number or a boolean is dropped.
- The measurements a callback causes itself, such as a query, get no contributed attributes. A callback therefore never calls itself.

### Read metrics at export time

Some values already live in another system, such as a queue size. Read them when the metrics are exported, with an observable instrument:

```php
use Illuminate\Support\Facades\Queue;
use Nwidart\Metrics\Facades\Metrics;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

Metrics::onExport(function (MeterProviderInterface $provider): void {
    $provider->getMeter('app')->createObservableGauge('queue.size', '{job}', null, [],
        fn (ObserverInterface $observer) => $observer->observe(Queue::size(), ['queue' => 'default']));
});
```

The callback runs once per export, in the export process. Requests only keep the callback, so they pay nothing for it. A callback that throws is reported and skipped. The values are never stored: they describe the process and the server that export them.

## Built-in instrumentation

| Metric | Attributes |
|---|---|
| `http.server.request.duration` (histogram, seconds) | `http.request.method`, `http.route`, `http.response.status_code`, `url.scheme`, `network.protocol.version`, `error.type` |
| `db.client.operation.duration` (histogram, seconds) | `db.system.name`, `db.namespace`, `db.operation.name`, `server.address`, `server.port` |
| `laravel.db.transaction.duration` (histogram, seconds) | `db.system.name`, `db.namespace`, `server.address`, `server.port`, `laravel.db.transaction.result` (`committed`, `rolled_back`) |
| `laravel.db.queries` (histogram, queries) | `http.route` for a request, `laravel.job.name` for a queued job |
| `laravel.eloquent.models` (counter) | `laravel.eloquent.event` (`retrieved`, `created`, `updated`, `deleted`, `restored`), `laravel.eloquent.model` |
| `laravel.eloquent.models.pruned` (counter) | `laravel.eloquent.model` |
| `messaging.process.duration` (histogram, seconds) | `messaging.system`, `messaging.operation.name` (`process`), `messaging.destination.name`, `laravel.job.name`, `error.type` |
| `messaging.client.sent.messages` (counter) | `messaging.system`, `messaging.operation.name` (`send`), `messaging.destination.name`, `laravel.job.name` |
| `laravel.scheduler.task.duration` (histogram, seconds) | `laravel.scheduler.task.name`, `error.type` |
| `laravel.exceptions` (counter) | `exception.type` |
| `laravel.cache.operations` (counter) | `laravel.cache.store`, `laravel.cache.operation` (`read`, `write`, `delete`), `laravel.cache.result` (`hit`, `miss`, `success`, `failure`) |

Each one has its own switch. See [Configuration](#configuration).

### HTTP requests

`http.server.request.duration` runs from the moment the request arrives (`REQUEST_TIME_FLOAT`) to the moment the response is ready to send. A method outside the standard HTTP methods becomes `_OTHER`, so that a client cannot create series at will. `error.type` is the status code, for a status of 500 or more.

### Ignore routes

Requests to the routes in `metrics.ignore_routes` are not recorded, so that health probes and scrapes do not drown the application's own traffic:

```php
'ignore_routes' => ['/up', 'horizon.*'],
```

A pattern that starts with `/` matches the URI template (`/up`). Any other pattern matches the route name (`horizon.*`). `*` is a wildcard.

### Queries

`db.client.operation.duration` has one value per successful query. `db.operation.name` is the first keyword of the query, such as `SELECT`, when it is one of a fixed list. Laravel dispatches no event for a failed query, so failed queries are not recorded. A connection with separate read and write hosts has no `server.address`.

### Transactions

`laravel.db.transaction.duration` has one value per outer transaction, from its beginning to its commit or rollback. Its count is the number of transactions, and the share of `rolled_back` is the rollback rate. A nested transaction (a savepoint) is part of its outer one. Laravel dispatches no event when a commit fails or the connection is lost, so such a transaction is not recorded.

### Queries per request and per job

`laravel.db.queries` has one value per HTTP request and per queued job: the number of successful queries it ran, on every connection. A route whose requests run many queries often has an N+1, a relation loaded once per model instead of once for all of them. The first bucket holds the requests and jobs that ran no query. Requests to `metrics.ignore_routes` are not recorded. A sync job's queries count for the request that dispatched it. Commands are not recorded. The count ends when the response is ready, so the queries of a streamed response (`response()->stream()`), which run while it is sent, are not counted.

Transactions and query counts share the `db` switch (`METRICS_DB`) with `db.client.operation.duration`.

### Eloquent models

`laravel.eloquent.models` counts Eloquent's model events, per model class: `retrieved` once for each model a query returns, then `created`, `updated` (only when the model had changes), `deleted` (soft deletes too) and `restored`. A query that returns 10,000 rows dispatches 10,000 events, so the models are counted in memory and added to the counter when the metrics are flushed. Mass updates and deletes (`Order::where(...)->update()`) and models saved without events (`saveQuietly`, `withoutEvents`) dispatch no model event and are not counted.

`laravel.eloquent.models.pruned` counts the models `model:prune` removes. Turn both off with `METRICS_ELOQUENT=false`.

### Queue jobs

`messaging.process.duration` has one value per attempt:

- An attempt that throws has the exception class as `error.type`.
- A job that fails itself, or has no attempts left, has `error.type` = `failed`.
- An attempt that the worker stops at its timeout has `error.type` = `Illuminate\Queue\TimeoutExceededException`. It is merged before the worker exits.

`laravel.job.name` is the job class, which the conventions do not define. A queue worker merges after each job. A sync job merges with the request or command that dispatched it.

### Scheduled tasks

`laravel.scheduler.task.duration` has one value per run of a scheduled task.

- `laravel.scheduler.task.name` is the task's description, or its command without arguments: `php artisan report:send --token=secret` is `report:send`. Arguments can hold a secret and change on each run. Give two tasks that run one command a `description()` to tell them apart.
- `error.type` is the exception class when the task threw, and `failed` when its command exited with another code than 0.
- A task skipped by `withoutOverlapping()` is not recorded.
- A background task is timed until it has started.

### Exceptions

`laravel.exceptions` counts the exceptions the application reports, by class (`exception.type`). These are not counted:

- exceptions Laravel does not report, such as a 404 or a validation failure;
- an exception with its own `report()` method;
- an exception that an earlier `reportable()` callback stops.

### Cache operations

The OpenTelemetry semantic conventions define no cache metrics, so `laravel.cache.operations` and its attributes use the `laravel.` namespace. It counts Laravel's cache events, per store: a read is a hit or a miss, and a write or a delete is a success or a failure. The hit ratio is `hit / (hit + miss)`. The cache key is never an attribute. Limits:

- No duration: on Laravel 12.0, timing an operation would need state per key, and `many` has one start event for several keys.
- Operations that dispatch no event are not counted: `increment`, `decrement`, `flush`, locks, and `add` with a TTL on a store that adds natively (Redis, Memcached, database, DynamoDB, file).
- `many` and `putMany` count one operation per key. `remember` counts a read, then a write on a miss.
- A store with `'events' => false` in `config/cache.php` dispatches no events and is not counted.
- The memoized store (`Cache::memo()`) is not counted. The store under it is, on a memo miss.
- A failover store is counted under the name of the store that ran the operation.

### Metrics read on export

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
| `laravel.db.server.connections` | `db.system.name`, `db.namespace`, `server.address`, `server.port` | The default connection is MySQL, MariaDB or PostgreSQL |
| `laravel.db.server.connections.max` | The same | The default connection is MySQL, MariaDB or PostgreSQL |
| `laravel.db.migrations.pending` | | Always |

The semantic conventions define no PHP runtime metrics, so the PHP ones use a `php.` namespace, like `jvm.*` and `v8js.*`. `system.filesystem.usage` follows the conventions. `system.cpu.load_average.1m` is the name of the Collector's host metrics receiver.

The database metrics run one query each on every export, and have their own switches: `METRICS_DB_SERVER` for the connections, `METRICS_MIGRATIONS` for the pending migrations. `laravel.db.server.connections` counts the client connections open on the server of the default connection, from every client: `Threads_connected` on MySQL and MariaDB, the client backends of `pg_stat_activity` on PostgreSQL. SQL Server has neither metric: its session count leaves out idle sessions, and `@@max_connections` is not the configured limit. Under PHP-FPM, each busy worker holds one connection, so a server at its maximum refuses the next request's. `laravel.db.migrations.pending` is the number of migrations `php artisan migrate` would run, without those it skips (`Migrator::withoutMigrations()`, a `shouldRun()` that returns `false`): above 0 after a deploy, the code expects a schema the database does not have yet. A database that cannot be reached leaves these metrics out, without an error.

OPcache is off on the command line by default, so `metrics:push` usually has no OPcache metrics. There are no metrics for the exporting process itself (CPU time, memory), because each export runs in another short-lived process.

## Stores

| `METRICS_STORE` | Use it for | Notes |
|---|---|---|
| `redis` (default) | Production | One script call per flush. Writers never wait. Shared by every worker and host that uses the same Redis. Set the connection with `METRICS_REDIS_CONNECTION`. |
| `cache` | Development without Redis | One entry in a Laravel cache store (`METRICS_CACHE_STORE`, default: the default cache store). Each flush takes a lock, reads the entry and writes it back, so writers wait for each other. A flush that waits more than 1 second is dropped and logged. |

The `redis` store keeps the connection's own key prefix (`REDIS_PREFIX`), then adds `METRICS_PREFIX`. The braces in `{metrics}:` are a Redis Cluster hash tag that keeps every store key in one slot.

With the `cache` store:

- The cache driver decides who shares the numbers: `file` is one server, `database`, `redis` or `memcached` are every server, and `array` is one process only.
- The driver must support locks. Every Laravel driver does, except `apc`.
- `php artisan cache:clear` also removes the metrics. Prometheus reads this as a counter reset.
- The store's own cache reads and writes are not counted in `laravel.cache.operations`. With the `database` cache driver, its queries are recorded in `db.client.operation.duration`.

The merge rules and the cardinality limit are those of metrics-store. See its [stores](https://github.com/nWidart/metrics-store#stores).

## Export

The scrape and `metrics:push` both send `Metrics::export()`: the cumulative values of the store and the [metrics read on export](#metrics-read-on-export). While metrics are disabled (`METRICS_ENABLED=false`), an export sends nothing, even if the store still holds older values.

Leave metrics out of every export with a filter that returns `true` for them, for example the stored series of a meter you no longer record:

```php
use Nwidart\Metrics\Facades\Metrics;
use OpenTelemetry\SDK\Metrics\Data\Metric;

Metrics::exclude(fn (Metric $metric): bool => str_starts_with($metric->name, 'legacy.'));
```

### Prometheus

Turn on the scrape route with `METRICS_PROMETHEUS=true`. `GET /metrics` then serves the export in the Prometheus text format (version 0.0.4). The names follow the OpenTelemetry Prometheus compatibility rules:

- `http.server.request.duration` in seconds becomes `http_server_request_duration_seconds`.
- A counter ends in `_total`.
- Every sample has the `otel_scope_name` label.

The route is off by default, because metrics can tell more about the application than it should share. Protect it with `prometheus.middleware` or with the network. Change the path with `METRICS_PROMETHEUS_PATH`. Add labels to every sample with `prometheus.labels`, for example `['service' => 'shop']`.

If the store cannot be built or read, the route answers 503, so Prometheus marks the target down. A store that cannot be built, such as an unknown `METRICS_STORE`, never fails the application's requests: it is reported, and nothing is recorded.

The scrape is an HTTP request like the others. It is recorded in `http.server.request.duration` with `http.route` = `/metrics`, unless you [ignore](#ignore-routes) the route.

All workers and hosts share one store, so scrape one address, such as the load balancer.

### OTLP push

`php artisan metrics:push` sends the export to an OTLP/HTTP endpoint, such as an OpenTelemetry Collector or a backend that accepts OTLP. Each run sends the totals so far (cumulative), so a lost or repeated push does no harm.

1. Set the endpoint in `.env`. The package appends `/v1/metrics`:

   ```bash
   OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
   ```

2. Schedule the command in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('metrics:push')->everyMinute();
   ```

   When several servers run the scheduler, add `->onOneServer()`, so that only one server pushes.

`OTEL_EXPORTER_OTLP_PROTOCOL` is `http/protobuf` (default) or `http/json`. Headers, such as an API key, go in `metrics.push.headers`.

The resource is `service.name` (`OTEL_SERVICE_NAME`, or `APP_NAME`) and `deployment.environment.name` (`APP_ENV`), with any attribute you add in `metrics.push.resource`. It has no host or process attributes, because the store holds the totals of every process and host.

The command exits with 0 when the endpoint accepts the push, or when there is nothing to send. It exits with 1 when the store cannot be read or the endpoint refuses the push. A missing endpoint throws an error, unless there is nothing to send.

## Testing

`Metrics::fake()` keeps what the application records in memory, with the rules of the real stores. A test then needs no Redis and sees only its own metrics:

```php
use Nwidart\Metrics\Facades\Metrics;

$metrics = Metrics::fake();

$this->post('/orders', $order);

$metrics->assertValue('orders.placed', 1, ['channel' => 'web']);
$metrics->assertRecorded('http.server.request.duration', ['http.route' => '/orders']);
$metrics->assertNotRecorded('laravel.exceptions');
```

- Attributes match every series that has at least those attributes.
- A value is the sum of the matching series: the value of a counter or a gauge, and the count of a histogram.
- `$metrics->value()` returns that value, and `$metrics->metrics()` returns the OpenTelemetry data.
- `assertNothingRecorded()` checks that nothing was recorded at all.

## Performance and overhead

A request pays for four things:

- booting the SDK, once per request that records;
- recording, in the built-in listeners and your own code;
- one merge into Redis, after the response is sent;
- nothing for the metrics read on export.

### Why it is cheap

- **Recording does no I/O.** The listeners add to OpenTelemetry instruments, and the SDK aggregates in process memory.
- **The merge grows with series, not with events.** 30 `SELECT` queries on one connection are one series and one data point in the merge.
- **One merge per request, after the response.** `terminating` runs after Laravel has sent the response with `fastcgi_finish_request()`, so the client does not wait. A queue worker merges once per job.
- **Nothing recorded, nothing sent.** A request that records nothing makes no Redis call. An ignored route records nothing.
- **Small listeners.** Each listener is a singleton that builds its instrument once. The database listener computes the attributes of each connection once. The exception handler is not built for the package.
- **No resource detection.** The request's meter provider has an empty resource, because the resource detectors cost time on every request. `metrics:push` adds the resource.
- **Export-time metrics are read once per export.** Requests only keep their callbacks.
- **Failure isolation.** A Redis failure costs a warning through the OpenTelemetry logger (the PHP error log by default), never a failed request. The metrics of that merge are lost; the merge is not retried. The connection is opened on the first merge, so an unreachable Redis fails the merge and not the request. A store that cannot be built is reported once, and the process records nothing.

### Measured cost

There is no benchmark with a full Laravel application yet. The numbers come from three sources. Absolute numbers depend on the machine. Read them as orders of magnitude.

| Work | Cost |
|---|---|
| Boot the SDK (provider, reader, 4 instruments), per request | About 75 µs with an optimized autoloader, about 1 ms without |
| Record 8 values | About 30 µs |
| Database listener, per query | About 2 µs, and about 3 µs with Laravel's event dispatch |
| Cache listener, per cache event | About 1.5 µs, and about 3 µs with Laravel's event dispatch |
| Eloquent listener, per model event | About 1.5 µs with Laravel's event dispatch, under 10% of the time Eloquent takes to hydrate a model from SQLite |
| Merge, PHP worker time, after the response | 0.1 to 0.35 ms |
| Merge, Redis CPU | About 25 µs for 8 values, 60–72 µs for a request with 30 queries |
| Metrics read on export, per request | Nothing |

- The boot, record and merge numbers come from the metrics-store benchmark: PHP 8.4 FPM without Laravel, opcache on, phpredis with a persistent connection, and a local Redis, in Docker on a laptop. See its [performance and overhead](https://github.com/nWidart/metrics-store#performance-and-overhead) for the effect on response latency.
- The listener numbers come from a loop over the listener in Testbench, PHP 8.4 CLI, without Xdebug.
- The Eloquent number comes from timing `Order::all()` over 10,000 SQLite rows in Testbench, with and without the listener.
- The upper merge time comes from Testbench with predis and a local Redis: a request with 30 queries over 8 series, 6 cache counters and 1 HTTP value.

While workers have spare capacity, the merge barely touches response latency. Under saturation it acts like extra request time, because a worker that is still merging cannot take the next request.

### Keep the overhead low

- **Install with an optimized autoloader**: `composer install --optimize-autoloader`, or `composer dump-autoload -o`. Without it, booting the SDK takes about 1 ms instead of 75 µs.
- Use phpredis with a persistent connection (`'persistent' => true` on the connection in `config/database.php`). A new connection per request adds a handshake to every merge.
- Keep Redis close to the application. A remote Redis adds a network round trip to every merge.
- Ignore routes that probes and scrapers call often, with `metrics.ignore_routes`.
- Turn off the instrumentation you do not read, such as `METRICS_CACHE=false`. Turn off `METRICS_ELOQUENT` in an application that loads tens of thousands of models per request.
- Keep attribute values few, in your own metrics and in `Metrics::attributes()`. Each new series costs Redis calls on its first merge, and memory for as long as the store lives.
- Use the `cache` store for development only. Its writers wait for each other.

The metrics-store README lists the rest: [keep the overhead low](https://github.com/nWidart/metrics-store#keep-the-overhead-low).

## Operations and limits

The store's own limits apply: one Redis without eviction, one exporter, a push interval shorter than the backend's expiry, and counter resets after a cleared store. Read them in the metrics-store [operations and limits](https://github.com/nWidart/metrics-store#operations-and-limits). These are specific to Laravel:

- **Give the store a Redis without eviction.** Point `METRICS_REDIS_CONNECTION` at a connection whose Redis has `maxmemory-policy noeviction`, not at a cache Redis that evicts keys.
- **Run `metrics:push` on one server.** When several servers run the scheduler, add `->onOneServer()` to the schedule.
- **Rebuild the route cache after you change the scrape route.** With cached routes, the package does not register the route itself. Run `php artisan route:cache` again after you change `METRICS_PROMETHEUS` or `METRICS_PROMETHEUS_PATH`.
- **Two applications that share a Redis database, its key prefix and `METRICS_PREFIX` share their metrics.** Give each application its own `METRICS_PREFIX`.
- **Queue workers merge after each job.** `queue:work`, and Horizon, which runs it, keep one meter provider per worker process. Each job costs one merge, like a request.
- **Long-running commands merge when they end.** Call `Metrics::flush()` in a command that runs for a long time.
- **Laravel Octane is not tested.**
- **Redis Cluster is not tested.** A phpredis cluster connection is not supported: the merge fails and logs a warning.

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

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
