<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Routing\Route;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records laravel.db.queries, the number of successful queries one HTTP request or one queued job
 * ran, on every connection. A route that runs many queries for one request is often an N+1: a
 * relation loaded once per model instead of once for all of them.
 *
 * A request has http.route, a job laravel.job.name. Requests to metrics.ignore_routes are not
 * recorded. A job on the sync queue runs inside the request that dispatched it, and its queries
 * count for that request. Commands are not recorded: a queue worker is a command whose own
 * queries are not the work of any job.
 */
final class DatabaseQueryCountMetrics
{
    private const BUCKETS = [0, 1, 2, 5, 10, 25, 50, 100, 250, 500, 1000];

    private readonly HistogramInterface $queries;

    private int $count = 0;

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors, private readonly IgnoredRoutes $ignoredRoutes)
    {
        $this->queries = $meterProvider->getMeter('nwidart/laravel-metrics')->createHistogram(
            'laravel.db.queries',
            '{query}',
            'Number of database queries run by one HTTP request or one queued job.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
    }

    public function query(): void
    {
        $this->count++;
    }

    public function requestHandled(RequestHandled $event): void
    {
        $attributes = [];
        $route = $event->request->route();
        if ($route instanceof Route) {
            if ($this->ignoredRoutes->matches($route)) {
                $this->count = 0;

                return;
            }
            $attributes['http.route'] = IgnoredRoutes::template($route);
        }

        $this->record($attributes, $event);
    }

    public function jobProcessing(JobProcessing $event): void
    {
        if (! $event->job instanceof SyncJob) {
            $this->count = 0;
        }
    }

    public function jobEnded(JobProcessed|JobExceptionOccurred|JobTimedOut $event): void
    {
        if (! $event->job instanceof SyncJob) {
            $this->record(['laravel.job.name' => $event->job->resolveName()], $event);
        }
    }

    /**
     * @param  array<non-empty-string, string>  $attributes
     */
    private function record(array $attributes, object $event): void
    {
        $count = $this->count;
        $this->count = 0;

        $this->queries->record($count, [...$attributes, ...$this->contributors->for('laravel.db.queries', $event)]);
    }
}
