<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Nwidart\Metrics\ExportTimeMetrics;
use Nwidart\Metrics\Facades\Metrics;
use Nwidart\MetricsStore\InMemoryStore;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

final class MetricsFakeTest extends TestCase
{
    #[Test]
    public function the_facade_records_through_the_package_s_meter_provider(): void
    {
        Metrics::meter('shop')->createCounter('orders.placed')->add(2, ['channel' => 'web']);
        Metrics::flush();

        $this->assertSame('orders.placed', $this->collect()[0]->name);
    }

    #[Test]
    public function the_fake_keeps_what_was_recorded_in_memory(): void
    {
        $fake = Metrics::fake();
        $this->assertInstanceOf(InMemoryStore::class, $this->app->make(MetricStore::class));

        Metrics::meter()->createCounter('orders.placed')->add(2, ['channel' => 'web']);
        Metrics::meter()->createCounter('orders.placed')->add(3, ['channel' => 'api']);
        Metrics::meter()->createHistogram('checkout.duration', 's')->record(0.2);

        $fake->assertRecorded('orders.placed');
        $fake->assertValue('orders.placed', 5);
        $fake->assertValue('orders.placed', 2, ['channel' => 'web']);
        $fake->assertNotRecorded('orders.placed', ['channel' => 'pos']);
        $fake->assertValue('checkout.duration', 1);
        $this->assertNull($fake->value('refunds'));
        // Nothing reached Redis.
        $this->assertSame([], $this->rawKeys('{laravel-test}:*'));
    }

    #[Test]
    public function the_built_in_instrumentation_records_into_the_fake_even_after_it_was_used(): void
    {
        Cache::store('array')->get('warm-up');
        $fake = Metrics::fake();
        Route::get('/orders', fn () => 'ok');

        $this->get('/orders');
        Cache::store('array')->get('order:1');

        $fake->assertValue('http.server.request.duration', 1, ['http.route' => '/orders']);
        $fake->assertValue('laravel.cache.operations', 1, ['laravel.cache.result' => 'miss']);
    }

    #[Test]
    public function assertions_fail_with_a_clear_message(): void
    {
        $fake = Metrics::fake();
        $fake->assertNothingRecorded();

        try {
            $fake->assertRecorded('orders.placed', ['channel' => 'web']);
            $this->fail('The assertion should fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('The metric [orders.placed] was not recorded with {"channel":"web"}.', $e->getMessage());
        }
    }

    #[Test]
    public function attributes_and_export_callbacks_go_through_the_facade(): void
    {
        $fake = Metrics::fake();
        Metrics::attributes('laravel.cache.operations', fn (): array => ['app.module' => 'shop']);
        Metrics::onExport(fn (MeterProviderInterface $provider) => $provider->getMeter('app')
            ->createObservableGauge('queue.size', null, null, [], fn (ObserverInterface $observer) => $observer->observe(4)));

        Cache::store('array')->get('order:1');

        $fake->assertRecorded('laravel.cache.operations', ['app.module' => 'shop']);
        $sizes = [];
        foreach ($this->app->make(ExportTimeMetrics::class)->collect(ResourceInfoFactory::emptyResource()) as $metric) {
            if ($metric->name === 'queue.size' && $metric->data instanceof Gauge) {
                $sizes[] = $metric->data->dataPoints[0]->value;
            }
        }
        $this->assertSame([4], $sizes);
    }
}
