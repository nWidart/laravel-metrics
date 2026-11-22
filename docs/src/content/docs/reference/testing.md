---
title: Testing
description: The methods of MetricsFake, the in-memory store that Metrics::fake() installs for tests.
---

`Metrics::fake()` returns a `Nwidart\Metrics\Testing\MetricsFake`.

```php
use Nwidart\Metrics\Facades\Metrics;

$metrics = Metrics::fake();
```

## Installation

`Metrics::fake()` calls `MetricsFake::install($app)`, which:

1. forgets the meter provider and the listeners of the built-in instrumentation, so that they are built again on the fake store;
2. binds a new `Nwidart\MetricsStore\InMemoryStore` as `Nwidart\MetricsStore\MetricStore`.

The in-memory store follows the merge rules of the real stores. Its cardinality limit is 2000 series per metric, whatever [`cardinality_limit`](/laravel-metrics/reference/configuration/#cardinality_limit) is. It needs no Redis and holds only what the test records.

Instruments created before the call keep the previous meter provider.

## Matching rules

These rules apply to every method that takes `$name` and `$attributes`:

- `$name` is the OpenTelemetry metric name, such as `http.server.request.duration`, not the Prometheus name.
- `$attributes` matches every series that has at least those attributes, with equal values (`==`). `[]` matches every series of the metric.
- Only sums, gauges and histograms match.
- Every method flushes the meter provider first, so it sees everything recorded so far.

A value is the sum of the matching series:

| Instrument | Value of one series |
|---|---|
| Counter, up-down counter | The sum |
| Gauge | The last value |
| Histogram | The count of values |

## Methods

### `metrics()`

```php
public function metrics(): array
```

Returns the stored metrics as a `list<OpenTelemetry\SDK\Metrics\Data\Metric>`, with an empty resource. Metrics read on export are not included.

### `value()`

```php
public function value(string $name, array $attributes = []): int|float|null
```

Returns the value of the matching series, added up. Returns `null` when no series matches.

### `assertRecorded()`

```php
public function assertRecorded(string $name, array $attributes = []): void
```

Passes when at least one series matches. Failure message:

```text
The metric [<name>] was not recorded with {"<attribute>":"<value>"}.
```

### `assertValue()`

```php
public function assertValue(string $name, int|float $expected, array $attributes = []): void
```

Passes when at least one series matches and the value equals `$expected` (`assertEquals`). Failure messages: the one of `assertRecorded()`, or:

```text
The metric [<name>] with {"<attribute>":"<value>"} has another value.
```

### `assertNotRecorded()`

```php
public function assertNotRecorded(string $name, array $attributes = []): void
```

Passes when no series matches. Failure message:

```text
The metric [<name>] was recorded with {"<attribute>":"<value>"}.
```

### `assertNothingRecorded()`

```php
public function assertNothingRecorded(): void
```

Passes when the store holds no metric. Failure message, with the names of the stored metrics:

```text
Metrics were recorded: <name>, <name>.
```

The `with {...}` part of a message is left out when `$attributes` is empty.

## Example

```php
use Nwidart\Metrics\Facades\Metrics;

$metrics = Metrics::fake();

$this->post('/orders', $order);

$metrics->assertValue('orders.placed', 1, ['channel' => 'web']);
$metrics->assertRecorded('http.server.request.duration', ['http.route' => '/orders']);
$metrics->assertNotRecorded('laravel.exceptions');
```

See [Test your metrics](/laravel-metrics/guides/test-metrics/).
