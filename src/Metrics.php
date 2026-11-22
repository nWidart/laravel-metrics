<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\Testing\MetricsFake;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as SdkMeterProvider;
use Throwable;

/**
 * The entry point behind the Metrics facade.
 */
final class Metrics
{
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

    /** Records into memory instead of the store, and returns assertions on what was recorded. */
    public function fake(): MetricsFake
    {
        return MetricsFake::install($this->app);
    }
}
