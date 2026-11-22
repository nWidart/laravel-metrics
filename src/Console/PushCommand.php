<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Nwidart\Metrics\Metrics;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

/**
 * Sends the cumulative values of the store to an OTLP endpoint. Run it from the scheduler: each run
 * sends the totals so far, so a lost or repeated push does no harm.
 */
final class PushCommand extends Command
{
    protected $signature = 'metrics:push';

    protected $description = 'Send the stored metrics to the OTLP endpoint';

    public function handle(Metrics $metrics, Repository $config): int
    {
        try {
            $exported = $metrics->export($this->resource($config));
        } catch (Throwable $e) {
            report($e);
            $this->components->error('The metrics store cannot be read: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($exported === []) {
            $this->components->info('The store holds no metrics.');

            return self::SUCCESS;
        }

        // A configuration error, such as a missing endpoint, is thrown as it is.
        $exporter = $this->laravel->make(MetricExporterInterface::class);
        try {
            $sent = $exporter->export($exported);
            $exporter->shutdown();
        } catch (Throwable $e) {
            report($e);
            $sent = false;
        }

        if (! $sent) {
            $this->components->error('The OTLP endpoint did not accept the metrics.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Sent %d metrics.', count($exported)));

        return self::SUCCESS;
    }

    /**
     * Only the configured attributes: the store aggregates every process and host, so a process or
     * host attribute would be wrong.
     */
    private function resource(Repository $config): ResourceInfo
    {
        $attributes = [];
        $configured = $config->get('metrics.push.resource');
        foreach (is_array($configured) ? $configured : [] as $name => $value) {
            if (is_scalar($value) && $value !== '') {
                $attributes[(string) $name] = $value;
            }
        }

        return ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create($attributes)));
    }
}
