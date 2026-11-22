<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Response;
use Nwidart\Metrics\Metrics;
use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

/**
 * Serves Metrics::export() in the Prometheus text format: the store, the metrics read on export, and
 * nothing while metrics are disabled. A store that cannot be built or read answers 503, so that
 * Prometheus marks the target down instead of reading an empty scrape. Other packages, such as
 * laravel-actuator, serve it under their own route.
 */
final class PrometheusController
{
    public function __invoke(Metrics $metrics, PrometheusTextFormat $format, Repository $config): Response
    {
        try {
            $exported = $metrics->export(ResourceInfoFactory::emptyResource());
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

        return new Response($format->render($exported, $labels), 200, ['Content-Type' => PrometheusTextFormat::CONTENT_TYPE]);
    }
}
