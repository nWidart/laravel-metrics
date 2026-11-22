<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\Instrumentation\EloquentMetrics;
use Nwidart\Metrics\Testing\MetricsFake;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as SdkMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Throwable;

/**
 * The entry point behind the Metrics facade.
 */
final class Metrics
{
    /** @var list<Closure(Metric): bool> */
    private array $exclusions = [];

    public function __construct(private readonly Application $app) {}

    /** A meter of the package's meter provider, to create instruments on. */
    public function meter(string $name = 'app', ?string $version = null): MeterInterface
    {
        return $this->app->make(MeterProviderInterface::class)->getMeter($name, $version);
    }

    /**
     * Adds attributes to a built-in metric. See AttributeContributors.
     *
     * @template TContext of object
     *
     * @param  Closure(TContext): array<string, string|int|float|bool>  $callback
     */
    public function attributes(string $metric, Closure $callback): void
    {
        $this->app->make(AttributeContributors::class)->add($metric, $callback);
    }

    /**
     * Registers observable instruments that are read when the metrics are exported. See
     * ExportTimeMetrics.
     *
     * @param  Closure(MeterProviderInterface): void  $callback
     */
    public function onExport(Closure $callback): void
    {
        $this->app->make(ExportTimeMetrics::class)->register($callback);
    }

    /**
     * Merges what was recorded since the last flush into the store. The package flushes when a
     * request or command terminates and after each queued job; call it in other long-running
     * work. A failure never reaches the caller.
     */
    public function flush(): void
    {
        // Eloquent models are counted in memory, one event per model, and added here.
        if ($this->app->resolved(EloquentMetrics::class)) {
            $this->app->make(EloquentMetrics::class)->record();
        }

        // Nothing was recorded if nothing built the provider.
        if (! $this->app->resolved(SdkMeterProvider::class)) {
            return;
        }

        try {
            $this->app->make(SdkMeterProvider::class)->forceFlush();
        } catch (Throwable) {
            // StoreExporter already logs store failures through the OpenTelemetry logger.
        }
    }

    /**
     * Leaves metrics out of every export (the Prometheus scrape and metrics:push), for example the
     * stored series of a meter that was turned off. The filter returns true to leave a metric out.
     *
     * @param  Closure(Metric): bool  $filter
     */
    public function exclude(Closure $filter): void
    {
        $this->exclusions[] = $filter;
    }

    /**
     * What an export sends: the cumulative values of the store and the metrics read on export,
     * without the excluded ones. Nothing while metrics are disabled, so that a store filled before
     * they were turned off is not served.
     *
     * @return list<Metric>
     *
     * @throws Throwable when the store cannot be built or read
     */
    public function export(ResourceInfo $resource): array
    {
        if (! $this->app->make(Repository::class)->get('metrics.enabled')) {
            return [];
        }

        // Resolved here, not injected: a store that cannot be built is a failed export, not an error page.
        $metrics = [
            ...$this->app->make(MetricStore::class)->collect($resource),
            ...$this->app->make(ExportTimeMetrics::class)->collect($resource),
        ];
        if ($this->exclusions === []) {
            return $metrics;
        }

        return array_values(array_filter($metrics, function (Metric $metric): bool {
            foreach ($this->exclusions as $exclude) {
                if ($exclude($metric)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Records into memory instead of the store, and returns assertions on what was recorded. */
    public function fake(): MetricsFake
    {
        return MetricsFake::install($this->app);
    }
}
