<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class DatabaseTransactionMetricsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    #[Test]
    public function an_outer_transaction_is_recorded_once_with_its_result(): void
    {
        DB::statement('create table orders (id integer primary key)');
        DB::transaction(function (): void {
            DB::insert('insert into orders default values');
            // A nested transaction is a savepoint of the outer one.
            DB::transaction(fn () => DB::insert('insert into orders default values'));
        });
        try {
            DB::transaction(function (): void {
                DB::insert('insert into orders default values');
                throw new RuntimeException('The payment failed.');
            });
        } catch (RuntimeException) {
        }
        DB::beginTransaction();
        DB::rollBack();
        $this->app->terminate();

        $byResult = [];
        foreach ($this->histogramPoints('laravel.db.transaction.duration') as $point) {
            $attributes = $point->attributes->toArray();
            ksort($attributes);
            $byResult[$attributes['laravel.db.transaction.result']] = [$point->count, $attributes];
        }
        ksort($byResult);

        $this->assertSame(['committed', 'rolled_back'], array_keys($byResult));
        $this->assertSame(1, $byResult['committed'][0]);
        $this->assertSame(2, $byResult['rolled_back'][0]);
        $this->assertSame(['db.namespace' => ':memory:', 'db.system.name' => 'sqlite', 'laravel.db.transaction.result' => 'committed'], $byResult['committed'][1]);
    }

    #[Test]
    public function a_rolled_back_savepoint_is_not_a_transaction_of_its_own(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        DB::rollBack();
        DB::commit();
        $this->app->terminate();

        $points = $this->histogramPoints('laravel.db.transaction.duration');
        $this->assertCount(1, $points);
        $this->assertSame(1, $points[0]->count);
        $this->assertSame('committed', $points[0]->attributes->get('laravel.db.transaction.result'));
    }

    #[Test]
    public function a_transaction_run_by_an_after_commit_callback_is_recorded_with_the_one_that_ran_it(): void
    {
        DB::transaction(function (): void {
            DB::afterCommit(fn () => DB::transaction(fn () => DB::select('select 1')));
        });
        $this->app->terminate();

        $points = $this->histogramPoints('laravel.db.transaction.duration');
        $this->assertCount(1, $points);
        $this->assertSame(2, $points[0]->count);
    }

    #[Test]
    public function two_connection_objects_with_one_name_have_their_own_transactions(): void
    {
        $read = DB::connection('testing');
        $write = DB::connection('testing::write');
        $this->assertNotSame($read, $write);
        $this->assertSame($read->getName(), $write->getName());

        $read->beginTransaction();
        $write->beginTransaction();
        $read->commit();
        $write->rollBack();
        $this->app->terminate();

        $byResult = [];
        foreach ($this->histogramPoints('laravel.db.transaction.duration') as $point) {
            $byResult[$point->attributes->get('laravel.db.transaction.result')] = $point->count;
        }
        ksort($byResult);
        $this->assertSame(['committed' => 1, 'rolled_back' => 1], $byResult);
    }

    #[Test]
    #[DefineEnvironment('disableDb')]
    public function it_can_be_turned_off(): void
    {
        DB::transaction(fn () => DB::select('select 1'));
        $this->app->terminate();

        $this->assertSame([], $this->histogramPoints('laravel.db.transaction.duration'));
    }

    protected function disableDb(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.db', false);
    }
}
