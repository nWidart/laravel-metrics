<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Nwidart\Metrics\Store\CacheStore;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\Attributes\Test;

/**
 * The merge rules are InMemoryStore's, which MetricStoreTestCase covers. These tests cover what the
 * cache adds: the wiring, sharing between processes, the lock and the cache events.
 */
final class CacheStoreTest extends TestCase
{
    private string $directory;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->directory = sys_get_temp_dir().'/metrics-cache-store-test-'.getmypid();
        $app['config']->set('cache.stores.metrics-file', ['driver' => 'file', 'path' => $this->directory, 'lock_path' => $this->directory]);
        $app['config']->set('metrics.store.driver', 'cache');
        $app['config']->set('metrics.store.cache_store', 'metrics-file');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem)->deleteDirectory($this->directory);
    }

    #[Test]
    public function the_cache_driver_needs_no_redis(): void
    {
        $this->assertInstanceOf(CacheStore::class, $this->store());

        $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders')->add(3);
        $this->app->terminate();

        $this->assertSame(3, $this->orders($this->store()));
    }

    #[Test]
    public function two_processes_share_the_entry_and_clear_removes_it(): void
    {
        $first = $this->fileStore();
        $second = $this->fileStore();

        $first->merge([$this->delta(2)]);
        $second->merge([$this->delta(5)]);

        $this->assertSame(7, $this->orders($first));
        $first->clear();
        $this->assertSame([], $second->collect(ResourceInfoFactory::emptyResource()));
    }

    #[Test]
    public function a_merge_that_cannot_get_the_lock_throws(): void
    {
        $this->file()->lock('{metrics}:state:lock', 10)->get();

        $this->expectException(LockTimeoutException::class);
        (new CacheStore($this->file(), lockWait: 0))->merge([$this->delta(1)]);
    }

    #[Test]
    public function the_store_s_own_cache_calls_are_not_counted(): void
    {
        $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders')->add(1);
        $this->app->terminate();
        $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders')->add(1);
        $this->app->terminate();

        $names = array_map(fn (Metric $metric) => $metric->name, $this->collect());
        $this->assertSame(['orders'], $names);
    }

    #[Test]
    public function an_unknown_driver_is_refused(): void
    {
        $this->app['config']->set('metrics.store.driver', 'apcu');
        $this->app->forgetInstance(MetricStore::class);

        try {
            $this->app->make(MetricStore::class);
            $this->fail('An unknown driver should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('[apcu]', $e->getMessage());
        } finally {
            // tearDown() clears the store.
            $this->app['config']->set('metrics.store.driver', 'cache');
        }
    }

    private function fileStore(): CacheStore
    {
        return new CacheStore($this->file());
    }

    private function file(): FileStore
    {
        $store = $this->app->make('cache')->store('metrics-file')->getStore();
        $this->assertInstanceOf(FileStore::class, $store);

        return $store;
    }

    private function delta(int $value): Metric
    {
        return new Metric(
            new InstrumentationScope('app', null, null, Attributes::create([])),
            ResourceInfoFactory::emptyResource(),
            'orders',
            null,
            null,
            new Sum([new NumberDataPoint($value, Attributes::create([]), 1, 2)], Temporality::DELTA, true),
        );
    }

    private function orders(MetricStore $store): int|float|null
    {
        foreach ($store->collect(ResourceInfoFactory::emptyResource()) as $metric) {
            if ($metric->name === 'orders' && $metric->data instanceof Sum) {
                return $metric->data->dataPoints[0]->value;
            }
        }

        return null;
    }
}
