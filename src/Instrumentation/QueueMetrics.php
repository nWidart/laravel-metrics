<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records queue jobs, as the OpenTelemetry messaging semantic conventions define it:
 *   messaging.process.duration       one value per attempt, from JobProcessing to JobProcessed, or
 *                                    to JobExceptionOccurred with error.type set to the exception class,
 *                                    or to JobTimedOut with error.type TimeoutExceededException
 *   messaging.client.sent.messages   one per job pushed to a queue (JobQueued)
 * The worker and the sync queue both dispatch these events. laravel.job.name is the job class
 * (or display name), which the conventions do not define but which says what ran.
 */
final class QueueMetrics
{
    /** Bucket boundaries the semantic conventions advise, in seconds. */
    private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    /** Laravel queue driver => messaging.system, where the conventions name it differently. */
    private const SYSTEMS = ['sqs' => 'aws_sqs'];

    private readonly HistogramInterface $duration;

    private readonly CounterInterface $sent;

    /** @var array<int, float> spl_object_id of the job => start, in seconds */
    private array $started = [];

    /** @var array<string, string> connection name => messaging.system */
    private array $systems = [];

    public function __construct(MeterProviderInterface $meterProvider, private readonly Repository $config)
    {
        $meter = $meterProvider->getMeter('nwidart/laravel-metrics');
        $this->duration = $meter->createHistogram(
            'messaging.process.duration',
            's',
            'Duration of processing operation.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
        $this->sent = $meter->createCounter('messaging.client.sent.messages', '{message}', 'Number of messages producer attempted to send to the broker.');
    }

    public function processing(JobProcessing $event): void
    {
        $this->started[spl_object_id($event->job)] = microtime(true);
    }

    public function processed(JobProcessed $event): void
    {
        // A job that failed itself ($this->fail()) or that had no attempts left still ends here.
        $this->recordProcess($event->connectionName, $event->job, $event->job->hasFailed() ? 'failed' : null);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->recordProcess($event->connectionName, $event->job, $event->exception::class);
    }

    /** The worker stopped the attempt at its timeout, and kills itself next. */
    public function timedOut(JobTimedOut $event): void
    {
        $this->recordProcess($event->connectionName, $event->job, TimeoutExceededException::class);
    }

    public function queued(JobQueued $event): void
    {
        $queue = $event->queue ?? $this->config->get("queue.connections.{$event->connectionName}.queue");
        $attributes = [
            'messaging.system' => $this->system($event->connectionName),
            'messaging.operation.name' => 'send',
            'laravel.job.name' => self::jobName($event->job),
        ];
        if (is_string($queue) && $queue !== '') {
            $attributes['messaging.destination.name'] = $queue;
        }

        $this->sent->add(1, $attributes);
    }

    private function recordProcess(string $connectionName, Job $job, ?string $errorType): void
    {
        $id = spl_object_id($job);
        if (! isset($this->started[$id])) {
            return;
        }
        $elapsed = microtime(true) - $this->started[$id];
        unset($this->started[$id]);

        $attributes = [
            'messaging.system' => $this->system($connectionName),
            'messaging.operation.name' => 'process',
            'laravel.job.name' => $job->resolveName(),
        ];
        $queue = $job->getQueue();
        if ($queue !== '') {
            $attributes['messaging.destination.name'] = $queue;
        }
        if ($errorType !== null) {
            $attributes['error.type'] = $errorType;
        }

        $this->duration->record(max(0.0, $elapsed), $attributes);
    }

    private function system(string $connectionName): string
    {
        if (! isset($this->systems[$connectionName])) {
            $driver = $this->config->get("queue.connections.{$connectionName}.driver");
            $driver = is_string($driver) && $driver !== '' ? $driver : $connectionName;
            $this->systems[$connectionName] = self::SYSTEMS[$driver] ?? $driver;
        }

        return $this->systems[$connectionName];
    }

    private static function jobName(mixed $job): string
    {
        if (is_object($job) && method_exists($job, 'displayName')) {
            $name = $job->displayName();
            if (is_string($name)) {
                return $name;
            }
        }

        return match (true) {
            is_object($job) => $job::class,
            is_string($job) => $job,
            default => 'unknown',
        };
    }
}
