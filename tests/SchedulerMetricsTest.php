<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\Instrumentation\SchedulerMetrics;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class SchedulerMetricsTest extends TestCase
{
    #[Test]
    public function each_run_is_recorded_by_task_and_failure(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->exec('true');
        $schedule->exec('false');
        $schedule->call(fn () => throw new RuntimeException('boom'))->description('cleanup');
        $schedule->call(fn () => null);

        $this->artisan('schedule:run');
        $this->app->terminate();

        $this->assertSame([
            'Callback' => null,
            'cleanup' => RuntimeException::class,
            'false' => 'failed',
            'true' => null,
        ], $this->byTask());
    }

    #[Test]
    public function a_task_skipped_for_overlapping_is_not_recorded(): void
    {
        $task = $this->app->make(Schedule::class)->exec('true')->withoutOverlapping();
        $task->mutex->create($task);

        $this->artisan('schedule:run');
        $this->app->terminate();

        $this->assertSame([], $this->byTask());
    }

    #[Test]
    public function a_background_task_is_timed_until_it_has_started(): void
    {
        $task = $this->app->make(Schedule::class)->exec('import.sh')->runInBackground();
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, 0.0));
        $this->app->terminate();

        $this->assertSame(['import.sh' => null], $this->byTask());
    }

    #[Test]
    public function the_name_leaves_out_the_arguments(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $this->assertSame('report:send', SchedulerMetrics::name($schedule->command('report:send --token=secret')));
        $this->assertSame('backup.sh', SchedulerMetrics::name($schedule->exec('TOKEN=secret backup.sh --all')));
        $this->assertSame('Nightly report', SchedulerMetrics::name($schedule->command('report:send --token=secret')->description('Nightly report')));
    }

    #[Test]
    #[DefineEnvironment('disableScheduler')]
    public function it_can_be_turned_off(): void
    {
        $this->app->make(Schedule::class)->exec('true');
        $this->artisan('schedule:run');
        $this->app->terminate();

        $this->assertSame([], $this->byTask());
    }

    protected function disableScheduler(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.scheduler', false);
    }

    /**
     * error.type of each recorded task, by task name.
     *
     * @return array<string, mixed>
     */
    private function byTask(): array
    {
        $tasks = [];
        foreach ($this->histogramPoints('laravel.scheduler.task.duration') as $point) {
            $this->assertSame(1, $point->count);
            $tasks[(string) $point->attributes->get('laravel.scheduler.task.name')] = $point->attributes->get('error.type');
        }
        ksort($tasks);

        return $tasks;
    }
}
