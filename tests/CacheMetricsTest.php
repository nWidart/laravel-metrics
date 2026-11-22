<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class CacheMetricsTest extends TestCase
{
    private const PREFIX = 'metrics-cache-test:';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.prefix', self::PREFIX);
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default']);
    }

    protected function tearDown(): void
    {
        Cache::store('redis')->forget('order:1');

        parent::tearDown();
    }

    #[Test]
    public function it_counts_reads_writes_and_deletes_per_store(): void
    {
        $cache = Cache::store('array');
        $cache->put('order:1', 'paid', 60);
        $cache->put('order:2', 'paid', 60);
        $cache->get('order:1');
        $cache->get('order:1');
        $cache->get('order:3');
        $cache->forget('order:1');
        $this->app->terminate();

        $this->assertSame([
            'array delete success' => 1,
            'array read hit' => 2,
            'array read miss' => 1,
            'array write success' => 2,
        ], $this->counts());
    }

    #[Test]
    public function the_key_is_never_an_attribute(): void
    {
        Cache::store('array')->get('order:1');
        Cache::store('array')->get('order:2');
        $this->app->terminate();

        $points = $this->points();
        $this->assertCount(1, $points);
        $this->assertSame([
            'laravel.cache.operation' => 'read',
            'laravel.cache.result' => 'miss',
            'laravel.cache.store' => 'array',
        ], $points[0][0]);
        $this->assertSame(2, $points[0][1]);
    }

    #[Test]
    public function it_counts_failures_and_leaves_out_an_unknown_store(): void
    {
        event(new KeyWriteFailed('array', 'order:1', 'paid', 60));
        event(new KeyForgetFailed(null, 'order:1'));
        $this->app->terminate();

        $this->assertSame([
            '- delete failure' => 1,
            'array write failure' => 1,
        ], $this->counts());
    }

    #[Test]
    public function it_counts_operations_on_a_redis_store(): void
    {
        $cache = Cache::store('redis');
        $this->assertTrue($cache->put('order:1', 'paid', 60));
        $this->assertSame('paid', $cache->get('order:1'));
        $this->assertNull($cache->get('order:2'));
        $this->assertTrue($cache->forget('order:1'));
        $this->app->terminate();

        $this->assertSame([
            'redis delete success' => 1,
            'redis read hit' => 1,
            'redis read miss' => 1,
            'redis write success' => 1,
        ], $this->counts());
    }

    #[Test]
    #[DefineEnvironment('disableCache')]
    public function it_can_be_turned_off(): void
    {
        Cache::store('array')->put('order:1', 'paid', 60);
        Cache::store('array')->get('order:1');
        $this->app->terminate();

        $this->assertSame([], $this->points());
    }

    protected function disableCache(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.cache', false);
    }

    /**
     * Value of each point, keyed by "store operation result", sorted by key.
     *
     * @return array<string, int|float>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->points() as [$attributes, $value]) {
            $store = $attributes['laravel.cache.store'] ?? '-';
            $counts["{$store} {$attributes['laravel.cache.operation']} {$attributes['laravel.cache.result']}"] = $value;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Sorted attributes and value of each point of laravel.cache.operations.
     *
     * @return list<array{array<string, mixed>, int|float}>
     */
    private function points(): array
    {
        $points = [];
        foreach ($this->collect() as $metric) {
            if ($metric->name === 'laravel.cache.operations') {
                $this->assertInstanceOf(Sum::class, $metric->data);
                $this->assertTrue($metric->data->monotonic);
                foreach ($metric->data->dataPoints as $point) {
                    $attributes = $point->attributes->toArray();
                    ksort($attributes);
                    $points[] = [$attributes, $point->value];
                }
            }
        }

        return $points;
    }
}
