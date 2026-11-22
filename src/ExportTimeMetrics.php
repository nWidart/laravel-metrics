<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Closure;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Throwable;

/**
 * Metrics that are read when they are exported (a Prometheus scrape, metrics:push) and never
 * stored: values that another system already keeps, such as OPcache statistics or the free disk
 * space. They describe the process and the server that export them.
 *
 * A callback receives a meter provider that exists only during the export, and creates observable
 * instruments on it. Requests only keep the callbacks, so they pay nothing for these metrics.
 */
final class ExportTimeMetrics
{
    /** @var list<Closure(MeterProviderInterface): void> */
    private array $callbacks = [];

    /**
     * @param  Closure(MeterProviderInterface): void  $callback
     */
    public function register(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Runs every callback and reads what their instruments observe. A callback that throws is
     * reported and skipped: the other metrics are still worth an export.
     *
     * @return list<Metric>
     */
    public function collect(ResourceInfo $resource): array
    {
        if ($this->callbacks === []) {
            return [];
        }

        $exporter = new InMemoryExporter;
        $reader = new ExportingReader($exporter);
        $provider = (new MeterProviderBuilder)->setResource($resource)->addReader($reader)->build();

        foreach ($this->callbacks as $callback) {
            try {
                $callback($provider);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Shutting the provider down collects once; a collect() before it would run every
        // observable callback twice.
        $provider->shutdown();
        $metrics = array_values($exporter->collect(true));

        return $metrics;
    }
}
