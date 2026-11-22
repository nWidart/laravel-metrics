<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Nwidart\Metrics\Instrumentation\HttpServerMetrics;
use Nwidart\Metrics\Redis\LaravelRedisClient;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Metrics\MeterProviderInterface as ApiMeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

final class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/metrics.php', 'metrics');

        $this->app->singleton(RedisStore::class, function (): RedisStore {
            $config = $this->config();
            $connection = $config->get('metrics.store.connection');
            $prefix = $config->get('metrics.store.prefix');
            $limit = $config->get('metrics.cardinality_limit');

            return new RedisStore(
                new LaravelRedisClient($this->app->make('redis'), is_string($connection) ? $connection : null),
                is_string($prefix) ? $prefix : '{metrics}:',
                is_numeric($limit) ? (int) $limit : 2000,
            );
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
                ->addReader(new ExportingReader(new StoreExporter($this->app->make(RedisStore::class))))
                ->build();
        });
        $this->app->alias(MeterProviderInterface::class, ApiMeterProvider::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/metrics.php' => config_path('metrics.php'),
            ], 'metrics-config');
        }

        if (! $this->enabled()) {
            return;
        }

        if ($this->config()->get('metrics.register_global')) {
            Globals::registerInitializer(fn (Configurator $configurator): Configurator => $configurator
                ->withMeterProvider($this->app->make(MeterProviderInterface::class)));
        }

        // terminating() runs after PHP-FPM has sent the response (fastcgi_finish_request).
        $this->app->terminating(fn () => $this->flush());

        $events = $this->app->make(Dispatcher::class);
        // A queue worker runs many jobs in one process: merge after each one.
        $events->listen([JobProcessed::class, JobFailed::class], fn () => $this->flush());

        if ($this->config()->get('metrics.instrumentation.http')) {
            $events->listen(RequestHandled::class, [HttpServerMetrics::class, 'handle']);
        }
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

    private function enabled(): bool
    {
        return (bool) $this->config()->get('metrics.enabled');
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
