---
title: Add attributes to built-in metrics
description: Split the built-in metrics by a value of your application, such as a tenant or a module, or bound an attribute that has too many values.
---

To add an attribute to a built-in metric, register a callback with `Metrics::attributes()`. The callback receives what the measurement is about and returns attributes. Its attributes win over built-in ones of the same name.

## Add a tenant to the HTTP metric

Register the callback in the `boot()` method of a service provider:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Foundation\Http\Events\RequestHandled;
use Nwidart\Metrics\Facades\Metrics;

public function boot(): void
{
    Metrics::attributes('http.server.request.duration', fn (RequestHandled $event): array => [
        'app.tenant' => $event->request->user()?->tenant->slug ?? 'none',
    ]);
}
```

Return a value for every measurement, such as `none`, so that every series has the attribute.

## Add an attribute to other metrics

Each built-in metric passes its own context to the callback:

| Metric | The callback receives |
|---|---|
| `http.server.request.duration` | `Illuminate\Foundation\Http\Events\RequestHandled` |
| `db.client.operation.duration` | `Illuminate\Database\Events\QueryExecuted` |
| `laravel.db.transaction.duration` | `TransactionCommitted` or `TransactionRolledBack` |
| `laravel.db.queries` | `RequestHandled`, `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` |
| `laravel.eloquent.models.pruned` | `ModelsPruned` |
| `messaging.process.duration` | `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut` |
| `messaging.client.sent.messages` | `JobQueued` |
| `laravel.cache.operations` | The `Illuminate\Cache\Events\CacheEvent` |
| `laravel.scheduler.task.duration` | `ScheduledTaskFinished` or `ScheduledTaskFailed` |
| `laravel.exceptions` | The `Throwable` |

To add the same attribute to several metrics, register one callback per metric:

```php
$module = fn (): array => ['app.module' => app(CurrentModule::class)->name()];

Metrics::attributes('http.server.request.duration', $module);
Metrics::attributes('db.client.operation.duration', $module);
Metrics::attributes('laravel.exceptions', $module);
```

To tag queued jobs by the queue connection:

```php
Metrics::attributes('messaging.process.duration', fn (object $event): array => [
    'app.queue.connection' => $event->connectionName,
]);
```

The context of `messaging.process.duration` has three possible classes, so type the parameter as `object` or as a union.

## Bound an attribute that has too many values

A callback can also replace a built-in attribute with a value from a small set. When the application creates queues at runtime, such as one queue per tenant, put every queue under one name:

```php
Metrics::attributes('messaging.process.duration', fn (): array => [
    'messaging.destination.name' => 'tenant',
]);
Metrics::attributes('messaging.client.sent.messages', fn (): array => [
    'messaging.destination.name' => 'tenant',
]);
```

To keep the queues that you created yourself:

```php
Metrics::attributes('messaging.process.duration', fn (object $event): array => [
    'messaging.destination.name' => str_starts_with($event->job->getQueue(), 'tenant-')
        ? 'tenant'
        : $event->job->getQueue(),
]);
```

## Check what the callback adds

With `Metrics::fake()`, assert the attribute in a test:

```php
$metrics = Metrics::fake();

$this->actingAs($user)->get('/dashboard');

$metrics->assertRecorded('http.server.request.duration', ['app.tenant' => 'acme']);
```

## What the package does with the callback

- A callback that throws is reported once and skipped.
- A value that is not a string, a number or a boolean is dropped.
- The measurements a callback causes itself, such as a query, get no contributed attributes.

## Related

- [Metrics facade reference](/laravel-metrics/reference/facade/)
- [Built-in metrics: HTTP](/laravel-metrics/reference/metrics/http/)
- [Cardinality](/laravel-metrics/explanation/cardinality/)
