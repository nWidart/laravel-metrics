---
title: Leave metrics out of exports
description: Stop the scrape and metrics:push from sending some metrics, such as the stored series of a meter you no longer record.
---

To keep metrics out of every export, register a filter with `Metrics::exclude()`. The filter receives each metric and returns `true` to leave it out. It applies to the Prometheus scrape and to `metrics:push`.

## Register a filter

Register it in the `boot()` method of a service provider:

```php title="app/Providers/AppServiceProvider.php"
use Nwidart\Metrics\Facades\Metrics;
use OpenTelemetry\SDK\Metrics\Data\Metric;

public function boot(): void
{
    Metrics::exclude(fn (Metric $metric): bool => str_starts_with($metric->name, 'legacy.'));
}
```

Each call adds a filter. A metric that any filter matches is left out.

## Filter by other fields

The filter receives an `OpenTelemetry\SDK\Metrics\Data\Metric`. Besides `name`, it has `unit`, `description` and `instrumentationScope`.

To leave out everything one meter recorded:

```php
Metrics::exclude(fn (Metric $metric): bool => $metric->instrumentationScope->getName() === 'billing');
```

The built-in metrics use the meter `nwidart/laravel-metrics`. `Metrics::meter()` uses `app` unless you give another name.

## Leave out one built-in metric

To stop recording a whole built-in instrumentation, turn it off instead: see [Turn off instrumentation](/laravel-metrics/guides/turn-off-instrumentation/). Its series stay in the store, so also exclude them until the store is cleared:

```php
Metrics::exclude(fn (Metric $metric): bool => $metric->name === 'laravel.cache.operations');
```

## Remove the series from the store

A filter hides series but keeps them in the store. To remove every series, clear the store:

```bash
php artisan tinker --execute="app(Nwidart\MetricsStore\MetricStore::class)->clear()"
```

:::caution
This removes every series of the application, not only the excluded ones. Backends read it as a counter reset.
:::

## Related

- [Metrics facade reference](/laravel-metrics/reference/facade/)
- [Stores reference](/laravel-metrics/reference/stores/)
- [Turn off instrumentation](/laravel-metrics/guides/turn-off-instrumentation/)
