<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nwidart\Metrics\Tests\Fixtures\CountOrders;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class DatabaseQueryCountMetricsTest extends TestCase
{
    private const QUEUE = 'query-count-test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => self::QUEUE, 'retry_after' => 90, 'block_for' => null]);
        $app['config']->set('queue.failed.driver', 'null');
        $app['config']->set('metrics.ignore_routes', ['/up']);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/orders/{order}', function () {
            DB::select('select 1');
            DB::select('select 2');
            // A sync job runs inside the request: its query is the request's.
            CountOrders::dispatch();

            return 'ok';
        });
        Route::get('/up', fn () => DB::select('select 1') === [] ? 'down' : 'up');
    }

    protected function tearDown(): void
    {
        $this->app->make('redis')->connection()->command('del', ['queues:'.self::QUEUE, 'queues:'.self::QUEUE.':reserved', 'queues:'.self::QUEUE.':delayed', 'queues:'.self::QUEUE.':notify']);

        parent::tearDown();
    }

    #[Test]
    public function each_request_records_its_queries_by_route_and_ignored_routes_are_left_out(): void
    {
        $this->get('/orders/1')->assertOk();
        $this->get('/orders/2')->assertOk();
        $this->get('/up')->assertOk();
        $this->app->terminate();

        $points = $this->histogramPoints('laravel.db.queries');
        $this->assertCount(1, $points);
        $this->assertSame(['http.route' => '/orders/{order}'], $points[0]->attributes->toArray());
        $this->assertSame(2, $points[0]->count);
        $this->assertEquals(6, $points[0]->sum);
    }

    #[Test]
    public function each_worker_job_records_only_its_own_queries(): void
    {
        CountOrders::dispatch(3)->onConnection('redis');
        CountOrders::dispatch(0)->onConnection('redis');
        DB::select('select 1');

        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => self::QUEUE, '--stop-when-empty' => true, '--sleep' => 0])->run();

        $points = $this->histogramPoints('laravel.db.queries');
        $this->assertCount(1, $points);
        $this->assertSame(['laravel.job.name' => CountOrders::class], $points[0]->attributes->toArray());
        $this->assertSame(2, $points[0]->count);
        $this->assertEquals(3, $points[0]->sum);
        // One job of 3 queries, one of none: the first bucket is "0 or fewer".
        $this->assertSame(1, $points[0]->bucketCounts[0]);
    }

    #[Test]
    #[DefineEnvironment('disableDb')]
    public function it_can_be_turned_off(): void
    {
        $this->get('/orders/1')->assertOk();
        $this->app->terminate();

        $this->assertSame([], $this->histogramPoints('laravel.db.queries'));
    }

    protected function disableDb(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.db', false);
    }
}
