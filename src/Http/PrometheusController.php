<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Response;
use Nwidart\Metrics\ExportTimeMetrics;
use Nwidart\MetricsStore\MetricStore;
use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

/**
 * Serves the cumulative values of the store in the Prometheus text format. A store that cannot be
 * read answers 503, so that Prometheus marks the target down instead of reading an empty scrape.
 */
final class PrometheusController
{
    public function __invoke(MetricStore $store, ExportTimeMetrics $exportTime, PrometheusTextFormat $format, Repository $config): Response
    {
        try {
            $metrics = $store->collect(ResourceInfoFactory::emptyResource());
            $observed = $exportTime->collect(ResourceInfoFactory::emptyResource());
        } catch (Throwable $e) {
            report($e);

            return new Response("The metrics store cannot be read.\n", 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $labels = [];
        $configured = $config->get('metrics.prometheus.labels');
        foreach (is_array($configured) ? $configured : [] as $name => $value) {
            if (is_scalar($value)) {
                $labels[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return new Response($format->render([...$metrics, ...$observed], $labels), 200, ['Content-Type' => PrometheusTextFormat::CONTENT_TYPE]);
    }
}
