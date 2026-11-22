---
title: Read values at export time
description: Report values that another system already holds, such as a queue size or a row count, when the metrics are exported.
---

To report a value that lives in another system, read it when the metrics are exported. Register a callback with `Metrics::onExport()`, and create observable instruments on the meter provider it receives.

The callback runs once per export: once per scrape of `/metrics`, or once per `metrics:push`. Requests only keep the callback.

## Report the size of a queue

Register the callback in the `boot()` method of a service provider:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Support\Facades\Queue;
use Nwidart\Metrics\Facades\Metrics;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

public function boot(): void
{
    Metrics::onExport(function (MeterProviderInterface $provider): void {
        $provider->getMeter('app')->createObservableGauge(
            'app.queue.size',
            '{job}',
            'Jobs waiting in each queue.',
            [],
            function (ObserverInterface $observer): void {
                foreach (['default', 'emails'] as $queue) {
                    $observer->observe(Queue::size($queue), ['messaging.destination.name' => $queue]);
                }
            },
        );
    });
}
```

## Report a row count

```php
use Illuminate\Support\Facades\DB;

Metrics::onExport(function (MeterProviderInterface $provider): void {
    $provider->getMeter('app')->createObservableGauge(
        'app.orders.pending',
        '{order}',
        null,
        [],
        fn (ObserverInterface $observer) => $observer->observe(
            DB::table('orders')->where('status', 'pending')->count(),
        ),
    );
});
```

The query runs in the export process. Keep it cheap: it runs on every scrape or push.

## Report a total that another system counts

For a value that only goes up, such as the total of jobs a third-party API has accepted, use an observable counter. Observe the total, not the change:

```php
$provider->getMeter('app')->createObservableCounter(
    'app.mailer.sent',
    '{message}',
    null,
    [],
    fn (ObserverInterface $observer) => $observer->observe($mailer->totalSent()),
);
```

Prometheus shows it as `app_mailer_sent_total`.

## Know where the callback runs

- With Prometheus, it runs in the web request that answers the scrape.
- With `metrics:push`, it runs in the scheduler's command process.

The values are never stored. They describe what the exporting process sees at that moment. A value that differs per server, such as the free disk space, comes from the exporting server only.

## What happens on failure

A callback that throws is reported and skipped. The other metrics are still exported.

## Related

- [Metrics facade reference](/laravel-metrics/reference/facade/)
- [Metrics read on export](/laravel-metrics/reference/metrics/export-time/)
- [How it works](/laravel-metrics/explanation/how-it-works/)
