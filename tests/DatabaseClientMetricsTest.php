<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class DatabaseClientMetricsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    #[Test]
    public function it_records_each_query_by_operation(): void
    {
        DB::statement('create table orders (id integer primary key, total integer)');
        DB::insert('insert into orders (total) values (?)', [10]);
        DB::insert('insert into orders (total) values (?)', [20]);
        DB::select('select * from orders');
        DB::select('/* report */ SELECT sum(total) from orders');
        $this->app->terminate();

        $byOperation = [];
        foreach ($this->histogramPoints('db.client.operation.duration') as $point) {
            $attributes = $point->attributes->toArray();
            ksort($attributes);
            $byOperation[$attributes['db.operation.name']] = [$point->count, $attributes];
        }
        ksort($byOperation);

        $this->assertSame(['CREATE', 'INSERT', 'SELECT'], array_keys($byOperation));
        $this->assertSame(2, $byOperation['INSERT'][0]);
        $this->assertSame(2, $byOperation['SELECT'][0]);
        $this->assertSame(['db.namespace' => ':memory:', 'db.operation.name' => 'SELECT', 'db.system.name' => 'sqlite'], $byOperation['SELECT'][1]);
    }

    #[Test]
    public function a_server_connection_has_its_address_and_an_unknown_statement_has_no_operation(): void
    {
        $connection = new MySqlConnection(fn () => throw new RuntimeException('not connected'), 'shop', '', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => '3306', 'name' => 'mysql']);
        event(new QueryExecuted('select 1', [], 2.5, $connection));
        event(new QueryExecuted('lock tables orders write', [], 1.0, $connection));
        $this->app->terminate();

        $points = $this->histogramPoints('db.client.operation.duration');
        $byOperation = [];
        foreach ($points as $point) {
            $attributes = $point->attributes->toArray();
            ksort($attributes);
            $byOperation[$attributes['db.operation.name'] ?? 'none'] = [$point->sum, $attributes];
        }

        $this->assertSame([
            'db.namespace' => 'shop',
            'db.operation.name' => 'SELECT',
            'db.system.name' => 'mysql',
            'server.address' => 'db.internal',
            'server.port' => 3306,
        ], $byOperation['SELECT'][1]);
        $this->assertSame(0.0025, $byOperation['SELECT'][0]);
        $this->assertArrayNotHasKey('db.operation.name', $byOperation['none'][1]);
    }

    #[Test]
    #[DefineEnvironment('disableDb')]
    public function it_can_be_turned_off(): void
    {
        DB::select('select 1');
        $this->app->terminate();

        $this->assertSame([], $this->histogramPoints('db.client.operation.duration'));
    }

    protected function disableDb(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.db', false);
    }
}
