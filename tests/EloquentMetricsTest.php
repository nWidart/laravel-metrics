<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nwidart\Metrics\Facades\Metrics;
use Nwidart\Metrics\Tests\Fixtures\ExpiredOrder;
use Nwidart\Metrics\Tests\Fixtures\Order;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class EloquentMetricsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->integer('total');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    #[Test]
    public function models_are_counted_by_event_and_class_when_the_metrics_are_flushed(): void
    {
        $order = Order::create(['total' => 10]);
        Order::create(['total' => 20]);
        Order::create(['total' => 30]);
        $this->assertCount(3, Order::all());
        $order->update(['total' => 15]);
        // No change, no "updated" event.
        $order->update(['total' => 15]);
        $order->delete();
        $order->restore();

        $this->assertSame([], $this->sums('laravel.eloquent.models'), 'Models are added to the counter only when the metrics are flushed.');
        $this->app->terminate();

        $this->assertSame([
            [['laravel.eloquent.event' => 'created', 'laravel.eloquent.model' => Order::class], 3],
            [['laravel.eloquent.event' => 'deleted', 'laravel.eloquent.model' => Order::class], 1],
            [['laravel.eloquent.event' => 'restored', 'laravel.eloquent.model' => Order::class], 1],
            [['laravel.eloquent.event' => 'retrieved', 'laravel.eloquent.model' => Order::class], 3],
            [['laravel.eloquent.event' => 'updated', 'laravel.eloquent.model' => Order::class], 2],
        ], $this->sums('laravel.eloquent.models'));
    }

    #[Test]
    public function a_second_flush_adds_only_the_models_counted_since_the_first(): void
    {
        Order::create(['total' => 10]);
        $this->app->terminate();
        Order::create(['total' => 20]);
        $this->app->terminate();

        $this->assertSame([[['laravel.eloquent.event' => 'created', 'laravel.eloquent.model' => Order::class], 2]], $this->sums('laravel.eloquent.models'));
    }

    #[Test]
    public function a_fake_installed_after_the_first_model_still_sees_the_next_ones(): void
    {
        Order::create(['total' => 10]);
        $this->app->terminate();

        $fake = Metrics::fake();
        Order::create(['total' => 20]);

        $fake->assertValue('laravel.eloquent.models', 1, ['laravel.eloquent.event' => 'created']);
    }

    #[Test]
    public function a_fake_does_not_inherit_models_counted_before_it(): void
    {
        Order::create(['total' => 10]);

        $fake = Metrics::fake();
        Order::create(['total' => 20]);

        $fake->assertValue('laravel.eloquent.models', 1, ['laravel.eloquent.event' => 'created']);
    }

    #[Test]
    public function pruned_models_are_counted_once_across_chunks_and_runs(): void
    {
        ExpiredOrder::insert([['total' => -1], ['total' => -2], ['total' => -3], ['total' => 4]]);
        // 3 models in chunks of 2: Laravel dispatches the running totals 2, then 3.
        $this->artisan('model:prune', ['--model' => ExpiredOrder::class, '--chunk' => 2])->assertSuccessful();
        // model:prune forgets the ModelsPruned listeners when it ends: the second run still counts.
        ExpiredOrder::insert([['total' => -5], ['total' => -6], ['total' => -7]]);
        $this->artisan('model:prune', ['--model' => ExpiredOrder::class, '--chunk' => 2])->assertSuccessful();
        $this->app->terminate();

        $this->assertSame([[['laravel.eloquent.model' => ExpiredOrder::class], 6]], $this->sums('laravel.eloquent.models.pruned'));
    }

    #[Test]
    public function a_running_total_that_does_not_grow_starts_another_run(): void
    {
        event(new ModelsPruned(Order::class, 500));
        event(new ModelsPruned(Order::class, 20));
        $this->app->terminate();

        $this->assertSame([[['laravel.eloquent.model' => Order::class], 520]], $this->sums('laravel.eloquent.models.pruned'));
    }

    #[Test]
    #[DefineEnvironment('disableEloquent')]
    public function it_can_be_turned_off(): void
    {
        Order::create(['total' => 10]);
        $this->app->terminate();

        $this->assertSame([], $this->sums('laravel.eloquent.models'));
    }

    protected function disableEloquent(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.eloquent', false);
    }
}
