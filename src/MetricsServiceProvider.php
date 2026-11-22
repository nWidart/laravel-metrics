<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nwidart\Metrics\Console\PushCommand;
use Nwidart\Metrics\Http\PrometheusController;
use Nwidart\Metrics\Instrumentation\CacheMetrics;
use Nwidart\Metrics\Instrumentation\DatabaseClientMetrics;
use Nwidart\Metrics\Instrumentation\HttpServerMetrics;
use Nwidart\Metrics\Instrumentation\QueueMetrics;
use Nwidart\Metrics\Redis\LaravelRedisClient;
use Nwidart\Metrics\Store\CacheStore;
use Nwidart\MetricsStore\MetricStore;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Metrics\MeterProviderInterface as ApiMeterProvider;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

final class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/metrics.php', 'metrics');

        $this->app->singleton(MetricStore::class, function (): MetricStore {
            $config = $this->config();
            $prefix = $config->get('metrics.store.prefix');
            $prefix = is_string($prefix) ? $prefix : '{metrics}:';
            $limit = $config->get('metrics.cardinality_limit');
            $limit = is_numeric($limit) ? (int) $limit : 2000;

            return match ($driver = $config->get('metrics.store.driver')) {
                'redis' => $this->redisStore($prefix, $limit),
                'cache' => $this->cacheStore($prefix, $limit),
                default => throw new InvalidArgumentException('Unknown metrics store driver ['.(is_scalar($driver) ? $driver : get_debug_type($driver)).']: use redis or cache.'),
            };
        });

        // One provider per application instance, so one per PHP-FPM request. It keeps only the
        // delta since the last flush; the store holds the cumulative values.
        $this->app->singleton(MeterProviderInterface::class, function (): MeterProviderInterface {
            if (! $this->enabled()) {
                return new NoopMeterProvider;
            }

            return (new MeterProviderBuilder)
                // The store keeps no resource, and the resource detectors cost time on every request.
                ->setResource(ResourceInfoFactory::emptyResource())
                ->addReader(new ExportingReader(new StoreExporter($this->app->make(MetricStore::class))))
                ->build();
        });
        $this->app->alias(MeterProviderInterface::class, ApiMeterProvider::class);

        // Built from the configuration, not from OTEL_* variables, so that it works with a cached
        // configuration, which leaves .env unread.
        $this->app->bind(MetricExporterInterface::class, function (): MetricExporterInterface {
            $config = $this->config();
            $endpoint = $config->get('metrics.push.endpoint');
            $protocol = $config->get('metrics.push.protocol');
            $headers = $config->get('metrics.push.headers');
            $timeout = $config->get('metrics.push.timeout');
            if (! is_string($endpoint) || $endpoint === '') {
                throw new InvalidArgumentException('Set metrics.push.endpoint (OTEL_EXPORTER_OTLP_ENDPOINT) to push metrics.');
            }

            $sent = [];
            foreach (is_array($headers) ? $headers : [] as $name => $value) {
                if (is_string($value)) {
                    $sent[(string) $name] = $value;
                }
            }

            $transport = (new OtlpHttpTransportFactory)->create(
                rtrim($endpoint, '/').'/v1/metrics',
                $protocol === 'http/json' ? ContentTypes::JSON : ContentTypes::PROTOBUF,
                $sent,
                null,
                is_numeric($timeout) ? (float) $timeout : 10.0,
            );

            // The store's metrics are cumulative already; the exporter sends what it receives.
            return new MetricExporter($transport);
        });

        // Singletons: Laravel would otherwise build a listener, and its instrument, on every event.
        $this->app->singleton(HttpServerMetrics::class);
        $this->app->singleton(DatabaseClientMetrics::class);
        $this->app->singleton(QueueMetrics::class);
        $this->app->singleton(CacheMetrics::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/metrics.php' => config_path('metrics.php'),
            ], 'metrics-config');

            $this->commands([PushCommand::class]);
        }

        if (! $this->enabled()) {
            return;
        }

        if ($this->config()->get('metrics.register_global')) {
            Globals::registerInitializer(fn (Configurator $configurator): Configurator => $configurator
                ->withMeterProvider($this->app->make(MeterProviderInterface::class)));
        }

        $this->registerPrometheusRoute();

        // terminating() runs after PHP-FPM has sent the response (fastcgi_finish_request).
        $this->app->terminating(fn () => $this->flush());

        $events = $this->app->make(Dispatcher::class);
        if ($this->config()->get('metrics.instrumentation.http')) {
            $events->listen(RequestHandled::class, [HttpServerMetrics::class, 'handle']);
        }
        if ($this->config()->get('metrics.instrumentation.db')) {
            $events->listen(QueryExecuted::class, [DatabaseClientMetrics::class, 'handle']);
        }
        if ($this->config()->get('metrics.instrumentation.queue')) {
            $events->listen(JobQueued::class, [QueueMetrics::class, 'queued']);
            $events->listen(JobProcessing::class, [QueueMetrics::class, 'processing']);
            $events->listen(JobProcessed::class, [QueueMetrics::class, 'processed']);
            $events->listen(JobExceptionOccurred::class, [QueueMetrics::class, 'exceptionOccurred']);
        }
        if ($this->config()->get('metrics.instrumentation.cache')) {
            $events->listen(CacheHit::class, [CacheMetrics::class, 'hit']);
            $events->listen(CacheMissed::class, [CacheMetrics::class, 'missed']);
            $events->listen(KeyWritten::class, [CacheMetrics::class, 'written']);
            $events->listen(KeyWriteFailed::class, [CacheMetrics::class, 'writeFailed']);
            $events->listen(KeyForgotten::class, [CacheMetrics::class, 'forgotten']);
            $events->listen(KeyForgetFailed::class, [CacheMetrics::class, 'forgetFailed']);
        }

        // A queue worker runs many jobs in one process: merge after each one. Registered after the
        // instrumentation, so the job's own metrics are in this flush. A sync job runs inside the
        // request or command that dispatched it, which flushes when it terminates.
        $events->listen(
            [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
            function (JobProcessed|JobExceptionOccurred|JobFailed $event): void {
                if (! $event->job instanceof SyncJob) {
                    $this->flush();
                }
            },
        );
    }

    /** Cached routes already hold this route, from the request that ran route:cache. */
    private function registerPrometheusRoute(): void
    {
        $config = $this->config();
        if (! $config->get('metrics.prometheus.enabled') || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }

        $path = $config->get('metrics.prometheus.path');
        $middleware = $config->get('metrics.prometheus.middleware');
        $this->app->make(Registrar::class)
            ->get(is_string($path) ? $path : 'metrics', PrometheusController::class)
            ->middleware(array_values(array_filter(is_array($middleware) ? $middleware : [$middleware], is_string(...))))
            ->name('metrics.prometheus');
    }

    /** Merges what was recorded since the last flush into the store. A failure never reaches the caller. */
    private function flush(): void
    {
        // Nothing was recorded if nothing built the provider.
        if (! $this->app->resolved(MeterProviderInterface::class)) {
            return;
        }

        try {
            $this->app->make(MeterProviderInterface::class)->forceFlush();
        } catch (Throwable) {
            // StoreExporter already logs store failures through the OpenTelemetry logger.
        }
    }

    private function redisStore(string $prefix, int $limit): RedisStore
    {
        $connection = $this->config()->get('metrics.store.connection');

        return new RedisStore(new LaravelRedisClient($this->app->make('redis'), is_string($connection) ? $connection : null), $prefix, $limit);
    }

    private function cacheStore(string $prefix, int $limit): CacheStore
    {
        $name = $this->config()->get('metrics.store.cache_store');
        // The raw store: a cache repository would dispatch events for the store's own reads and writes.
        $store = $this->app->make(CacheFactory::class)->store(is_string($name) && $name !== '' ? $name : null)->getStore();
        if (! $store instanceof LockProvider) {
            throw new InvalidArgumentException('The metrics cache store must support locks (every Laravel cache driver except apc does).');
        }

        return new CacheStore($store, $prefix.'state', $limit);
    }

    private function enabled(): bool
    {
        return (bool) $this->config()->get('metrics.enabled');
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
