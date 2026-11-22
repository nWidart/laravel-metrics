<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\Tests\Fixtures\ShipOrder;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class QueueMetricsTest extends TestCase
{
    private const QUEUE = 'metrics-test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => self::QUEUE, 'retry_after' => 90, 'block_for' => null]);
        $app['config']->set('queue.failed.driver', 'null');
    }

    protected function tearDown(): void
    {
        $this->app->make('redis')->connection()->command('del', ['queues:'.self::QUEUE, 'queues:'.self::QUEUE.':reserved', 'queues:'.self::QUEUE.':delayed', 'queues:'.self::QUEUE.':notify']);

        parent::tearDown();
    }

    #[Test]
    public function a_sync_job_is_recorded_and_merged_when_the_request_terminates(): void
    {
        ShipOrder::dispatch();

        $this->assertSame([], $this->collect(), 'A sync job must not flush in the middle of the request.');
        $this->app->terminate();

        $points = $this->histogramPoints('messaging.process.duration');
        $this->assertCount(1, $points);
        $this->assertSame(1, $points[0]->count);
        $this->assertSame([
            'laravel.job.name' => ShipOrder::class,
            'messaging.destination.name' => 'sync',
            'messaging.operation.name' => 'process',
            'messaging.system' => 'sync',
        ], $this->sorted($points[0]->attributes->toArray()));
    }

    #[Test]
    public function a_sync_job_that_throws_has_the_exception_as_error_type(): void
    {
        try {
            ShipOrder::dispatch(fails: true);
            $this->fail('The sync job should throw.');
        } catch (RuntimeException) {
        }
        $this->app->terminate();

        $points = $this->histogramPoints('messaging.process.duration');
        $this->assertCount(1, $points);
        $this->assertSame(RuntimeException::class, $points[0]->attributes->get('error.type'));
    }

    #[Test]
    public function a_worker_merges_after_each_job_and_sent_jobs_are_counted(): void
    {
        ShipOrder::dispatch()->onConnection('redis');
        ShipOrder::dispatch(fails: true)->onConnection('redis');

        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => self::QUEUE, '--stop-when-empty' => true, '--sleep' => 0])->run();

        // No terminate(): the worker merged after each job.
        $byError = [];
        foreach ($this->histogramPoints('messaging.process.duration') as $point) {
            $byError[$point->attributes->get('error.type') ?? 'none'] = $this->sorted($point->attributes->toArray());
        }
        ksort($byError);
        $this->assertSame(['RuntimeException', 'none'], array_keys($byError));
        $this->assertSame([
            'laravel.job.name' => ShipOrder::class,
            'messaging.destination.name' => self::QUEUE,
            'messaging.operation.name' => 'process',
            'messaging.system' => 'redis',
        ], $byError['none']);

        // The sends were recorded in this test's "request" and merge when it terminates.
        $this->app->terminate();
        $sent = $this->sum('messaging.client.sent.messages');
        $this->assertSame([['laravel.job.name' => ShipOrder::class, 'messaging.destination.name' => self::QUEUE, 'messaging.operation.name' => 'send', 'messaging.system' => 'redis'], 2], $sent);
    }

    #[Test]
    #[DefineEnvironment('disableQueue')]
    public function it_can_be_turned_off(): void
    {
        ShipOrder::dispatch();
        $this->app->terminate();

        $this->assertSame([], $this->histogramPoints('messaging.process.duration'));
    }

    protected function disableQueue(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.queue', false);
    }

    /**
     * Attributes and value of the only point of a sum.
     *
     * @return array{array<string, mixed>, int|float}|null
     */
    private function sum(string $name): ?array
    {
        foreach ($this->collect() as $metric) {
            if ($metric->name === $name && $metric->data instanceof Sum) {
                $this->assertCount(1, $metric->data->dataPoints);
                $point = $metric->data->dataPoints[0];

                return [$this->sorted($point->attributes->toArray()), $point->value];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sorted(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }
}
