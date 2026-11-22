---
title: Metrics facade
description: The methods of the Metrics facade and the Nwidart\Metrics\Metrics class, and the container bindings of the package.
---

`Nwidart\Metrics\Facades\Metrics` is the facade of `Nwidart\Metrics\Metrics`. The class is a singleton in the container. Every method below exists on both.

```php
use Nwidart\Metrics\Facades\Metrics;

Metrics::meter('app')->createCounter('orders.placed', '{order}')->add(1);
```

The methods are in the order of the class.

## `meter()`

```php
public function meter(string $name = 'app', ?string $version = null): OpenTelemetry\API\Metrics\MeterInterface
```

Returns a meter of the package's meter provider. `$name` and `$version` are the instrumentation scope of the instruments created on it. The scope name becomes the `otel_scope_name` label in the Prometheus scrape, and a non-empty version becomes `otel_scope_version`.

While metrics are [disabled](/laravel-metrics/reference/configuration/#enabled), the meter belongs to a no-op provider and records nothing.

```php
Metrics::meter('app')
    ->createHistogram('checkout.duration', 's', null, ['ExplicitBucketBoundaries' => [0.1, 0.5, 1, 5]])
    ->record(0.42);
```

## `attributes()`

```php
public function attributes(string $metric, Closure $callback): void
```

Adds a callback that contributes attributes to one built-in metric.

- `$metric` is the name of a built-in metric. A callback for another name is never called. Instruments created with `meter()` never call callbacks.
- `$callback` receives the context of the measurement, listed below, and returns an array of attribute name to value.
- Several callbacks for one metric run in the order they were added. A later callback wins over an earlier one for the same name.
- Contributed attributes win over built-in attributes of the same name.

| Metric | The callback receives |
|---|---|
| `http.server.request.duration` | `Illuminate\Foundation\Http\Events\RequestHandled` |
| `db.client.operation.duration` | `Illuminate\Database\Events\QueryExecuted` |
| `messaging.process.duration` | `Illuminate\Queue\Events\JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` |
| `messaging.client.sent.messages` | `Illuminate\Queue\Events\JobQueued` |
| `laravel.cache.operations` | The `Illuminate\Cache\Events\CacheEvent`: `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyWriteFailed`, `KeyForgotten` or `KeyForgetFailed` |
| `laravel.scheduler.task.duration` | `Illuminate\Console\Events\ScheduledTaskFinished` or `ScheduledTaskFailed` |
| `laravel.exceptions` | The reported `Throwable` |

Rules for the returned value:

- A return value that is not an array contributes nothing.
- An attribute with an empty name is dropped.
- A value that is not a string, an integer, a float or a boolean is dropped.

Failures:

- A callback that throws is skipped. The first exception in a process is passed to `report()`. Later ones are not reported.
- While a callback runs, the measurements it causes, such as a query or a cache read, get no contributed attributes.

```php
use Illuminate\Foundation\Http\Events\RequestHandled;

Metrics::attributes('http.server.request.duration', fn (RequestHandled $event): array => [
    'app.tenant' => $event->request->user()?->tenant->slug ?? 'none',
]);
```

See [Add attributes to built-in metrics](/laravel-metrics/guides/add-attributes/).

## `onExport()`

```php
public function onExport(Closure $callback): void
```

Adds a callback that runs on each export, before the metrics are read.

- `$callback` receives an `OpenTelemetry\API\Metrics\MeterProviderInterface` that exists only during the export. Its resource is the resource of the export.
- The callback creates observable instruments on that provider. Their values are read once, when the export collects them.
- The values are never stored. They are added to the export after the store's metrics.
- A callback that throws is passed to `report()` and skipped. The other callbacks still run.
- No callback runs while metrics are disabled.

```php
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

Metrics::onExport(function (MeterProviderInterface $provider): void {
    $provider->getMeter('app')->createObservableGauge('queue.size', '{job}', null, [],
        fn (ObserverInterface $observer) => $observer->observe(Queue::size(), ['queue' => 'default']));
});
```

The [metrics read on export](/laravel-metrics/reference/metrics/export-time/) of the package use this mechanism.

## `flush()`

```php
public function flush(): void
```

Merges what the meter provider recorded since the previous flush into the store.

- It does nothing when the meter provider was not built in this process.
- It never throws. A store failure is logged as a warning through the OpenTelemetry logger.

The package calls it:

- when the application terminates (`$app->terminating()`), after the response is sent;
- after `JobProcessed`, `JobExceptionOccurred`, `JobFailed` and `JobTimedOut`, except for a job of the `sync` queue.

## `exclude()`

```php
public function exclude(Closure $filter): void
```

Adds a filter to every export. `$filter` receives an `OpenTelemetry\SDK\Metrics\Data\Metric` and returns `true` to leave it out.

- Filters apply to the store's metrics and to the metrics read on export.
- A metric is left out when any filter returns `true`.
- Filters do not change the store.

```php
use OpenTelemetry\SDK\Metrics\Data\Metric;

Metrics::exclude(fn (Metric $metric): bool => str_starts_with($metric->name, 'legacy.'));
```

## `export()`

```php
public function export(OpenTelemetry\SDK\Resource\ResourceInfo $resource): array
```

Returns what an export sends, as a `list<OpenTelemetry\SDK\Metrics\Data\Metric>`:

1. the cumulative metrics of the store, with `$resource`;
2. the metrics of the `onExport()` callbacks;
3. without the metrics that an `exclude()` filter leaves out.

It returns `[]` while metrics are disabled. It throws when the store cannot be built or read.

The [Prometheus endpoint](/laravel-metrics/reference/prometheus-endpoint/) calls it with an empty resource. [`metrics:push`](/laravel-metrics/reference/metrics-push/) calls it with the resource of `metrics.push.resource`.

## `fake()`

```php
public function fake(): Nwidart\Metrics\Testing\MetricsFake
```

Replaces the store with an in-memory store, rebuilds the meter provider and the listeners on it, and returns assertions on what is recorded. See [Testing](/laravel-metrics/reference/testing/).

## Container bindings

| Abstract | Binding | Built from |
|---|---|---|
| `Nwidart\Metrics\Metrics` | Singleton | |
| `OpenTelemetry\SDK\Metrics\MeterProviderInterface` | Singleton | A provider with an empty resource and one `ExportingReader` on a `StoreExporter`. A `NoopMeterProvider` while metrics are disabled, or when the store cannot be built. |
| `OpenTelemetry\API\Metrics\MeterProviderInterface` | Alias of the SDK provider | |
| `Nwidart\MetricsStore\MetricStore` | Singleton | `store.driver`. See [Stores](/laravel-metrics/reference/stores/). |
| `OpenTelemetry\SDK\Metrics\MetricExporterInterface` | Bound, not shared | The OTLP exporter of `metrics:push`, from `metrics.push.*`. |

The listeners of the built-in instrumentation (`Nwidart\Metrics\Instrumentation\*`) are singletons. Each one creates its instruments once.

When the store cannot be built, the exception is passed to `report()` once, and the process records nothing.
