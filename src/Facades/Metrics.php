<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \OpenTelemetry\API\Metrics\MeterInterface meter(string $name = 'app', ?string $version = null)
 * @method static void attributes(string $metric, \Closure $callback)
 * @method static void onExport(\Closure $callback)
 * @method static void flush()
 * @method static void exclude(\Closure $filter)
 * @method static list<\OpenTelemetry\SDK\Metrics\Data\Metric> export(\OpenTelemetry\SDK\Resource\ResourceInfo $resource)
 * @method static \Nwidart\Metrics\Testing\MetricsFake fake()
 *
 * @see \Nwidart\Metrics\Metrics
 */
final class Metrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nwidart\Metrics\Metrics::class;
    }
}
