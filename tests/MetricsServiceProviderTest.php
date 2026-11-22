<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\MetricsServiceProvider;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterProviderInterface as ApiMeterProvider;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class MetricsServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_default_configuration(): void
    {
        $this->assertTrue(config('metrics.enabled'));
        $this->assertSame('default', config('metrics.store.connection'));
        $this->assertSame(2000, config('metrics.cardinality_limit'));
        $this->assertTrue(config('metrics.instrumentation.http'));
    }

    #[Test]
    public function a_published_configuration_without_the_newer_switches_gets_their_defaults(): void
    {
        // A config/metrics.php published by an older version, read before the provider registers.
        config()->set('metrics', ['instrumentation' => ['http' => true, 'db' => false]]);
        (new MetricsServiceProvider($this->app))->register();

        $this->assertFalse(config('metrics.instrumentation.db'));
        $this->assertTrue(config('metrics.instrumentation.eloquent'));
        $this->assertTrue(config('metrics.instrumentation.migrations'));
    }

    #[Test]
    public function the_api_and_sdk_meter_providers_are_one_singleton(): void
    {
        $this->assertSame($this->app->make(MeterProviderInterface::class), $this->app->make(ApiMeterProvider::class));
        $this->assertNotInstanceOf(NoopMeterProvider::class, $this->app->make(MeterProviderInterface::class));
    }

    #[Test]
    public function what_the_application_records_reaches_the_store_when_it_terminates(): void
    {
        $this->app->make(ApiMeterProvider::class)->getMeter('app')->createCounter('orders')->add(3);
        $this->assertSame([], $this->collect());

        $this->app->terminate();

        $metrics = $this->collect();
        $this->assertCount(1, $metrics);
        $this->assertSame('orders', $metrics[0]->name);
        $this->assertInstanceOf(Sum::class, $metrics[0]->data);
        $this->assertSame(3, $metrics[0]->data->dataPoints[0]->value);
    }

    #[Test]
    public function a_second_flush_adds_only_what_was_recorded_since_the_first(): void
    {
        $counter = $this->app->make(ApiMeterProvider::class)->getMeter('app')->createCounter('orders');
        $counter->add(3);
        $this->app->terminate();
        $counter->add(2);
        $this->app->terminate();

        $data = $this->collect()[0]->data;
        $this->assertInstanceOf(Sum::class, $data);
        $this->assertSame(5, $data->dataPoints[0]->value);
    }

    #[Test]
    public function the_connection_key_prefix_is_kept(): void
    {
        $this->app->make(ApiMeterProvider::class)->getMeter('app')->createCounter('orders')->add(1);
        $this->app->terminate();

        $prefix = config('database.redis.options.prefix');
        $this->assertIsString($prefix);
        $this->assertNotSame('', $prefix);
        $keys = $this->rawKeys('*{laravel-test}:*');
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith($prefix.'{laravel-test}:', $key);
        }
    }

    #[Test]
    public function an_unreachable_redis_does_not_fail_the_request(): void
    {
        // The Redis manager copies its configuration when it is built: rebuild it, and start this
        // "request" with a fresh store and provider, since setUp() already connected the store.
        config(['database.redis.default.port' => '1']);
        $this->forgetRedis();

        $this->app->make(ApiMeterProvider::class)->getMeter('app')->createCounter('orders')->add(1);
        $this->app->terminate();

        // Reached without an exception. With Redis back, nothing was stored.
        config(['database.redis.default.port' => getenv('REDIS_PORT') ?: '6379']);
        $this->forgetRedis();
        $this->assertSame([], $this->collect());
    }

    #[Test]
    #[DefineEnvironment('registerGlobal')]
    public function the_meter_provider_can_become_the_opentelemetry_global(): void
    {
        $this->assertSame($this->app->make(MeterProviderInterface::class), Globals::meterProvider());
        Globals::reset();
    }

    #[Test]
    #[DefineEnvironment('disable')]
    public function nothing_is_recorded_or_stored_when_disabled(): void
    {
        $this->assertInstanceOf(NoopMeterProvider::class, $this->app->make(MeterProviderInterface::class));
        $this->app->make(ApiMeterProvider::class)->getMeter('app')->createCounter('orders')->add(1);
        $this->get('/');

        $this->assertSame([], $this->collect());
    }

    private function forgetRedis(): void
    {
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance(MetricStore::class);
        $this->app->forgetInstance(MeterProviderInterface::class);
    }

    protected function registerGlobal(Application $app): void
    {
        Globals::reset();
        $app['config']->set('metrics.register_global', true);
    }

    protected function disable(Application $app): void
    {
        $app['config']->set('metrics.enabled', false);
    }
}
