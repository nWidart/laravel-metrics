<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Route;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records http.server.request.duration, as the OpenTelemetry semantic conventions define it.
 * The duration runs from REQUEST_TIME_FLOAT, which PHP-FPM, Octane and Symfony test requests
 * all set when the request arrives, to the moment the response is ready to send.
 */
final class HttpServerMetrics
{
    /** Bucket boundaries the semantic conventions advise, in seconds. */
    private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    /** Other methods become _OTHER, so that a client cannot create series at will. */
    private const METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'];

    private readonly HistogramInterface $duration;

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors)
    {
        $this->duration = $meterProvider->getMeter('nwidart/laravel-metrics')->createHistogram(
            'http.server.request.duration',
            's',
            'Duration of HTTP server requests.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
    }

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $status = $event->response->getStatusCode();
        $method = strtoupper($request->getRealMethod());

        $attributes = [
            'http.request.method' => in_array($method, self::METHODS, true) ? $method : '_OTHER',
            'http.response.status_code' => $status,
            'url.scheme' => $request->getScheme(),
        ];
        $route = $request->route();
        if ($route instanceof Route) {
            $attributes['http.route'] = '/'.ltrim($route->uri(), '/');
        }
        $protocol = $request->server('SERVER_PROTOCOL');
        if (is_string($protocol) && str_starts_with($protocol, 'HTTP/')) {
            $attributes['network.protocol.version'] = substr($protocol, 5);
        }
        if ($status >= 500) {
            $attributes['error.type'] = (string) $status;
        }

        $startedAt = $request->server('REQUEST_TIME_FLOAT');
        $elapsed = is_numeric($startedAt) ? microtime(true) - (float) $startedAt : 0.0;

        $this->duration->record(max(0.0, $elapsed), [...$attributes, ...$this->contributors->for('http.server.request.duration', $event)]);
    }
}
