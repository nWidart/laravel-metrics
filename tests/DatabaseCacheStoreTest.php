<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nwidart\Metrics\Store\CacheStore;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use PHPUnit\Framework\Attributes\Test;

/** The cache store on Laravel's database cache driver, on the database that the application queries. */
final class DatabaseCacheStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.stores.metrics-database', ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks', 'connection' => null]);
        $app['config']->set('metrics.store.driver', 'cache');
        $app['config']->set('metrics.store.cache_store', 'metrics-database');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    #[Test]
    public function the_store_s_own_queries_are_not_recorded(): void
    {
        $this->assertInstanceOf(CacheStore::class, $this->store());

        // The first flush merges the queries that created the tables, then each one merges what was
        // recorded since: nothing, when the merges' own queries are not recorded.
        $counts = [];
        for ($i = 0; $i < 3; $i++) {
            $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders')->add(1);
            $this->app->terminate();
            $counts[] = array_sum(array_map(fn ($point) => $point->count, $this->histogramPoints('db.client.operation.duration')));
        }

        $this->assertGreaterThan(0, $counts[0]);
        $this->assertSame([$counts[0], $counts[0], $counts[0]], $counts);
        $this->assertSame([[[], 3]], $this->sums('orders'));
    }
}
