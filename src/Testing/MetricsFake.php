<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Testing;

use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\Metrics;
use Nwidart\Metrics\MetricsServiceProvider;
use Nwidart\MetricsStore\InMemoryStore;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\Assert;

/**
 * Keeps what the application records in memory, with the rules of the real stores, so that a test
 * needs no Redis and sees only its own metrics. Every read flushes first.
 *
 * Attributes given to a method match every series that has at least those attributes. A value is
 * the sum of the matching series: the value of a sum or a gauge, and the count of a histogram.
 */
final class MetricsFake
{
    private function __construct(
        private readonly Application $app,
        private readonly InMemoryStore $store,
    ) {}

    public static function install(Application $app): self
    {
        // Built again on the fake store: the provider and the listeners hold the store or instruments.
        foreach ([MeterProviderInterface::class, ...MetricsServiceProvider::INSTRUMENTATION] as $abstract) {
            $app->forgetInstance($abstract);
        }
        $store = new InMemoryStore;
        $app->instance(MetricStore::class, $store);

        return new self($app, $store);
    }

    /**
     * @return list<Metric>
     */
    public function metrics(): array
    {
        $this->app->make(Metrics::class)->flush();

        return $this->store->collect(ResourceInfoFactory::emptyResource());
    }

    /**
     * The value of the matching series, added up, or null when none matches.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function value(string $name, array $attributes = []): int|float|null
    {
        $value = null;
        foreach ($this->points($name, $attributes) as $point) {
            $value = ($value ?? 0) + ($point instanceof HistogramDataPoint ? $point->count : $point->value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertRecorded(string $name, array $attributes = []): void
    {
        Assert::assertNotSame([], $this->points($name, $attributes), "The metric [{$name}] was not recorded".self::with($attributes).'.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertValue(string $name, int|float $expected, array $attributes = []): void
    {
        $this->assertRecorded($name, $attributes);
        Assert::assertEquals($expected, $this->value($name, $attributes), "The metric [{$name}]".self::with($attributes).' has another value.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertNotRecorded(string $name, array $attributes = []): void
    {
        Assert::assertSame([], $this->points($name, $attributes), "The metric [{$name}] was recorded".self::with($attributes).'.');
    }

    public function assertNothingRecorded(): void
    {
        $names = array_map(fn (Metric $metric) => $metric->name, $this->metrics());
        Assert::assertSame([], $names, 'Metrics were recorded: '.implode(', ', $names).'.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<NumberDataPoint|HistogramDataPoint>
     */
    private function points(string $name, array $attributes): array
    {
        $points = [];
        foreach ($this->metrics() as $metric) {
            $data = $metric->data;
            if ($metric->name !== $name || ! ($data instanceof Sum || $data instanceof Gauge || $data instanceof Histogram)) {
                continue;
            }
            foreach ($data->dataPoints as $point) {
                $actual = $point->attributes->toArray();
                if (array_intersect_key($actual, $attributes) == $attributes) {
                    $points[] = $point;
                }
            }
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function with(array $attributes): string
    {
        return $attributes === [] ? '' : ' with '.json_encode($attributes, JSON_UNESCAPED_SLASHES);
    }
}
