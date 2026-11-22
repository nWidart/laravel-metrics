---
title: Record your own metrics
description: Count business events, time operations and track levels with OpenTelemetry instruments that feed the shared store.
---

To record a metric of your own, create an OpenTelemetry instrument on a meter from `Metrics::meter()`, then add values to it. The package merges them into the store with the request, the command or the job.

## Count events

Use a counter for a value that only goes up, such as orders placed:

```php
use Nwidart\Metrics\Facades\Metrics;

Metrics::meter('app')
    ->createCounter('app.orders.placed', '{order}', 'Orders placed.')
    ->add(1, ['app.order.channel' => 'web']);
```

Prometheus shows it as `app_orders_placed_total`.

## Time an operation

Use a histogram, and give it bucket boundaries in the unit you record:

```php
$started = microtime(true);

$gateway->charge($order);

Metrics::meter('app')
    ->createHistogram('app.payment.duration', 's', 'Duration of payment calls.', [
        'ExplicitBucketBoundaries' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5],
    ])
    ->record(microtime(true) - $started, ['app.payment.gateway' => 'stripe']);
```

Always pass `ExplicitBucketBoundaries`. The SDK's default boundaries run from 0 to 10000, which suits milliseconds, not seconds.

Keep the boundaries of a histogram the same everywhere it is recorded. Other boundaries make another metric in the store.

## Track a level that goes up and down

Use an up-down counter for a level that your code changes, such as carts in progress:

```php
$carts = Metrics::meter('app')->createUpDownCounter('app.carts.active', '{cart}');

$carts->add(1);   // a cart opens
$carts->add(-1);  // a cart closes
```

Every process adds to the same total.

## Report a value that another system holds

For a value you read rather than change, such as a queue size or a row count, do not record it in requests. Read it at export time with an observable instrument. See [Read values at export time](/laravel-metrics/guides/read-at-export-time/).

Observable instruments created on `Metrics::meter()` in a request are not merged into the store.

## Inject the meter provider

To avoid the facade, inject the meter provider:

```php
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

final class OrderMetrics
{
    private readonly CounterInterface $placed;

    public function __construct(MeterProviderInterface $provider)
    {
        $this->placed = $provider->getMeter('app')->createCounter('app.orders.placed', '{order}');
    }

    public function placed(string $channel): void
    {
        $this->placed->add(1, ['app.order.channel' => $channel]);
    }
}
```

Bind such a class as a singleton, so that each process builds the instrument once:

```php title="app/Providers/AppServiceProvider.php"
public function register(): void
{
    $this->app->singleton(OrderMetrics::class);
}
```

## Record from libraries that use the OpenTelemetry API

The package's meter provider is the OpenTelemetry global by default. A library that records through `OpenTelemetry\API\Globals::meterProvider()` feeds the store with no extra code.

To turn this off:

```bash title=".env"
METRICS_REGISTER_GLOBAL=false
```

## Pick attribute values

Each distinct set of attribute values is a new series. Use values from a small, fixed set, such as a channel or a status. Never use an ID, an e-mail address or a URL. See [Cardinality](/laravel-metrics/explanation/cardinality/).

## Related

- [Metrics facade reference](/laravel-metrics/reference/facade/)
- [Metric names](/laravel-metrics/explanation/naming/)
- [Cardinality](/laravel-metrics/explanation/cardinality/)
- [Test your metrics](/laravel-metrics/guides/test-metrics/)
