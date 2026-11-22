<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nwidart\Metrics\Console\PushCommand;
use Nwidart\Metrics\Http\PrometheusController;
use Nwidart\Metrics\Instrumentation\CacheMetrics;
use Nwidart\Metrics\Instrumentation\DatabaseClientMetrics;
use Nwidart\Metrics\Instrumentation\DatabaseQueryCountMetrics;
use Nwidart\Metrics\Instrumentation\DatabaseServerMetrics;
use Nwidart\Metrics\Instrumentation\DatabaseTransactionMetrics;
use Nwidart\Metrics\Instrumentation\EloquentMetrics;
use Nwidart\Metrics\Instrumentation\ExceptionMetrics;
use Nwidart\Metrics\Instrumentation\HttpServerMetrics;
use Nwidart\Metrics\Instrumentation\IgnoredRoutes;
use Nwidart\Metrics\Instrumentation\MigrationMetrics;
use Nwidart\Metrics\Instrumentation\QueueMetrics;
use Nwidart\Metrics\Instrumentation\RuntimeMetrics;
use Nwidart\Metrics\Instrumentation\SchedulerMetrics;
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
    /**
     * The listeners of the built-in instrumentation. Each holds its instruments.
     *
     * @internal
     */
    public const INSTRUMENTATION = [
        HttpServerMetrics::class,
        DatabaseClientMetrics::class,
        DatabaseTransactionMetrics::class,
        DatabaseQueryCountMetrics::class,
        QueueMetrics::class,
        CacheMetrics::class,
        SchedulerMetrics::class,
        ExceptionMetrics::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/metrics.php', 'metrics');
        $this->mergeInstrumentationDefaults();

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

            // A store that cannot be built, such as an unknown driver, must not fail the request
            // either: report it, and record nothing in this process. An export answers with the error.
            try {
                $store = $this->app->make(MetricStore::class);
            } catch (Throwable $e) {
                // Bound first: reporting records laravel.exceptions, which asks for this provider.
                $this->app->instance(MeterProviderInterface::class, $noop = new NoopMeterProvider);
                report($e);

                return $noop;
            }

            return (new MeterProviderBuilder)
                // The store keeps no resource, and the resource detectors cost time on every request.
                ->setResource(ResourceInfoFactory::emptyResource())
                ->addReader(new ExportingReader(new StoreExporter($store)))
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
        foreach (self::INSTRUMENTATION as $listener) {
            $this->app->singleton($listener);
        }
        $this->app->singleton(EloquentMetrics::class);
        $this->app->singleton(IgnoredRoutes::class);
        $this->app->singleton(ExportTimeMetrics::class);
        $this->app->singleton(AttributeContributors::class);
        $this->app->singleton(Metrics::class);
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
            $events->listen(TransactionBeginning::class, [DatabaseTransactionMetrics::class, 'began']);
            $events->listen(TransactionCommitted::class, [DatabaseTransactionMetrics::class, 'committed']);
            $events->listen(TransactionRolledBack::class, [DatabaseTransactionMetrics::class, 'rolledBack']);
            $events->listen(QueryExecuted::class, [DatabaseQueryCountMetrics::class, 'query']);
            $events->listen(RequestHandled::class, [DatabaseQueryCountMetrics::class, 'requestHandled']);
            $events->listen(JobProcessing::class, [DatabaseQueryCountMetrics::class, 'jobProcessing']);
            $events->listen([JobProcessed::class, JobExceptionOccurred::class, JobTimedOut::class], [DatabaseQueryCountMetrics::class, 'jobEnded']);
        }
        if ($this->config()->get('metrics.instrumentation.eloquent')) {
            // A query dispatches one event per model it returns: resolve the listener once, not per event.
            $eloquent = null;
            $count = function (string $eventName) use (&$eloquent): void {
                ($eloquent ??= $this->app->make(EloquentMetrics::class))->count($eventName);
            };
            foreach (EloquentMetrics::EVENTS as $event) {
                $events->listen("eloquent.{$event}: *", $count);
            }
            // A pattern, not the class: model:prune forgets every ModelsPruned listener when it ends.
            $events->listen(ModelsPruned::class.'*', fn (string $eventName, array $payload) => $payload[0] instanceof ModelsPruned
                ? $this->app->make(EloquentMetrics::class)->pruned($payload[0])
                : null);
        }
        if ($this->config()->get('metrics.instrumentation.queue')) {
            $events->listen(JobQueued::class, [QueueMetrics::class, 'queued']);
            $events->listen(JobProcessing::class, [QueueMetrics::class, 'processing']);
            $events->listen(JobProcessed::class, [QueueMetrics::class, 'processed']);
            $events->listen(JobExceptionOccurred::class, [QueueMetrics::class, 'exceptionOccurred']);
            $events->listen(JobTimedOut::class, [QueueMetrics::class, 'timedOut']);
        }
        if ($this->config()->get('metrics.instrumentation.cache')) {
            $events->listen(CacheHit::class, [CacheMetrics::class, 'hit']);
            $events->listen(CacheMissed::class, [CacheMetrics::class, 'missed']);
            $events->listen(KeyWritten::class, [CacheMetrics::class, 'written']);
            $events->listen(KeyWriteFailed::class, [CacheMetrics::class, 'writeFailed']);
            $events->listen(KeyForgotten::class, [CacheMetrics::class, 'forgotten']);
            $events->listen(KeyForgetFailed::class, [CacheMetrics::class, 'forgetFailed']);
        }
        if ($this->config()->get('metrics.instrumentation.scheduler')) {
            $events->listen(ScheduledTaskStarting::class, [SchedulerMetrics::class, 'starting']);
            $events->listen(ScheduledTaskFinished::class, [SchedulerMetrics::class, 'finished']);
            $events->listen(ScheduledTaskFailed::class, [SchedulerMetrics::class, 'failed']);
        }
        if ($this->config()->get('metrics.instrumentation.exceptions')) {
            $this->countReportedExceptions();
        }
        if ($this->config()->get('metrics.instrumentation.runtime')) {
            $this->app->make(ExportTimeMetrics::class)->register(fn (ApiMeterProvider $provider) => $this->app->make(RuntimeMetrics::class)($provider));
        }
        // Only when illuminate/database is there: these read the database on every export.
        if ($this->config()->get('metrics.instrumentation.db_server') && $this->app->bound('db')) {
            $this->app->make(ExportTimeMetrics::class)->register(fn (ApiMeterProvider $provider) => $this->app->make(DatabaseServerMetrics::class)($provider));
        }
        if ($this->config()->get('metrics.instrumentation.migrations') && $this->app->bound('migrator')) {
            $this->app->make(ExportTimeMetrics::class)->register(fn (ApiMeterProvider $provider) => $this->app->make(MigrationMetrics::class)($provider));
        }

        // A queue worker runs many jobs in one process: merge after each one. Registered after the
        // instrumentation, so the job's own metrics are in this flush. A sync job runs inside the
        // request or command that dispatched it, which flushes when it terminates.
        // The worker kills itself right after JobTimedOut.
        $events->listen(
            [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, JobTimedOut::class],
            function (JobProcessed|JobExceptionOccurred|JobFailed|JobTimedOut $event): void {
                if (! $event->job instanceof SyncJob) {
                    $this->flush();
                }
            },
        );
    }

    /**
     * A config/metrics.php published by an older version has no switch for newer instrumentation,
     * and mergeConfigFrom() merges top-level keys only: those switches take their defaults.
     */
    private function mergeInstrumentationDefaults(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->config();
        /** @var array{instrumentation: array<string, mixed>} $defaults */
        $defaults = require __DIR__.'/../config/metrics.php';
        $published = $config->get('metrics.instrumentation');
        $config->set('metrics.instrumentation', [...$defaults['instrumentation'], ...(is_array($published) ? $published : [])]);
    }

    /** The handler is built only when it is first needed, which is usually never. */
    private function countReportedExceptions(): void
    {
        $count = function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable(function (Throwable $e): void {
                    $this->app->make(ExceptionMetrics::class)->report($e);
                });
            }
        };

        $this->app->afterResolving(ExceptionHandler::class, $count);
        if ($this->app->resolved(ExceptionHandler::class)) {
            $count($this->app->make(ExceptionHandler::class));
        }
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

    private function flush(): void
    {
        $this->app->make(Metrics::class)->flush();
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
