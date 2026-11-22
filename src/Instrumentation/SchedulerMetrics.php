<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records laravel.scheduler.task.duration, one value per run of a scheduled task, from
 * ScheduledTaskStarting to ScheduledTaskFinished or ScheduledTaskFailed. The OpenTelemetry semantic
 * conventions define no scheduler metric, so the name and laravel.scheduler.task.name are in the
 * laravel namespace. error.type is the exception class when the task threw, and "failed" when its
 * command exited with another code than 0.
 *
 * A task that Laravel skipped, such as a withoutOverlapping() task whose previous run still holds
 * the lock, is not recorded. A task that runs in the background is timed until it has started.
 */
final class SchedulerMetrics
{
    /** Bucket boundaries, in seconds: scheduled tasks run for up to minutes. */
    private const BUCKETS = [0.1, 0.5, 1, 5, 10, 30, 60, 300, 900];

    private readonly HistogramInterface $duration;

    /** @var array<int, float> spl_object_id of the task => start, in seconds */
    private array $started = [];

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors)
    {
        $this->duration = $meterProvider->getMeter('nwidart/laravel-metrics')->createHistogram(
            'laravel.scheduler.task.duration',
            's',
            'Duration of scheduled task runs.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->started[spl_object_id($event->task)] = microtime(true);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        // Only a foreground task that ran has an exit code; a background task gets it in another process.
        if ($task->exitCode === null && ! $task->runInBackground) {
            unset($this->started[spl_object_id($task)]);

            return;
        }

        $this->record($event, $task->exitCode !== null && $task->exitCode !== 0 ? 'failed' : null);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->record($event, $event->exception::class);
    }

    private function record(ScheduledTaskFinished|ScheduledTaskFailed $event, ?string $errorType): void
    {
        $task = $event->task;
        $id = spl_object_id($task);
        // Already recorded: a task can finish with a failure code and then fail.
        if (! isset($this->started[$id])) {
            return;
        }
        $elapsed = microtime(true) - $this->started[$id];
        unset($this->started[$id]);

        $attributes = ['laravel.scheduler.task.name' => self::name($task)];
        if ($errorType !== null) {
            $attributes['error.type'] = $errorType;
        }

        $this->duration->record(max(0.0, $elapsed), [...$attributes, ...$this->contributors->for('laravel.scheduler.task.duration', $event)]);
    }

    /**
     * The description of the task, or its command without arguments: "php artisan report:send
     * --token=secret" is "report:send". Arguments can hold a secret, and can change on each run.
     */
    public static function name(Event $task): string
    {
        if (is_string($task->description) || $task->command === null) {
            return $task->getSummaryForDisplay();
        }

        $words = preg_split('/\s+/', trim(Event::normalizeCommand($task->command)), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        // A shell command can start with variable assignments, such as "TOKEN=secret backup.sh".
        while ($words !== [] && preg_match('/^\w+=/', $words[0]) === 1) {
            array_shift($words);
        }
        if (in_array(trim($words[0] ?? '', '\'"'), ['php', PHP_BINARY], true) && trim($words[1] ?? '', '\'"') === 'artisan') {
            $words = array_slice($words, 2);
        }

        return trim($words[0] ?? 'command', '\'"');
    }
}
