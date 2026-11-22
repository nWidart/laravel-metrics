---
title: Scheduled tasks
description: The laravel.scheduler.task.duration histogram, its attributes and when it is recorded.
---

`Nwidart\Metrics\Instrumentation\SchedulerMetrics` records the duration of each run of a scheduled task. The OpenTelemetry semantic conventions define no scheduler metric, so the metric uses the `laravel.` namespace.

## Instrument

| Property | Value |
|---|---|
| Name | `laravel.scheduler.task.duration` |
| Kind | Histogram |
| Unit | `s` |
| Buckets | `0.1, 0.5, 1, 5, 10, 30, 60, 300, 900` |
| Description | `Duration of scheduled task runs.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `laravel_scheduler_task_duration_seconds` |

The duration runs from `ScheduledTaskStarting` to `ScheduledTaskFinished` or `ScheduledTaskFailed`. A task that runs in the background is timed until it has started.

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `laravel.scheduler.task.name` | string | See below | Always |
| `error.type` | string | The exception class, or `failed` | The task threw, or its command exited with a code other than `0` |

### `laravel.scheduler.task.name`

- A task with a `description()` has its description.
- A task without a command, such as a callback or a job, has the summary that `schedule:list` shows.
- A command has its first word, without arguments:
  - leading variable assignments, such as `TOKEN=secret`, are removed;
  - a leading `php artisan`, or the PHP binary and `artisan`, is removed;
  - quotes around the word are removed.

| Schedule | `laravel.scheduler.task.name` |
|---|---|
| `Schedule::command('report:send --token=secret')` | `report:send` |
| `Schedule::exec('TOKEN=secret backup.sh --full')` | `backup.sh` |
| `Schedule::command('report:send')->description('Daily report')` | `Daily report` |

### `error.type`

| Run | `error.type` |
|---|---|
| The task threw an exception (`ScheduledTaskFailed`) | The exception class |
| A foreground command exited with a code other than `0` | `failed` |
| The task succeeded | None |

## Recorded when

| Event | Effect |
|---|---|
| `Illuminate\Console\Events\ScheduledTaskStarting` | Starts the timer of the run |
| `Illuminate\Console\Events\ScheduledTaskFinished` | Records the run |
| `Illuminate\Console\Events\ScheduledTaskFailed` | Records the run, with the exception class |

Each run is recorded once. A run that finishes with a failure code and then fails is recorded at `ScheduledTaskFinished`, with `error.type` = `failed`.

## Not recorded

- A task that Laravel skips, such as a `withoutOverlapping()` task whose previous run still holds the lock.
- A foreground task that finishes without an exit code.
- An end event without a matching `ScheduledTaskStarting`.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `laravel.scheduler.task.duration` receives `ScheduledTaskFinished` or `ScheduledTaskFailed`, with `$event->task` (an `Illuminate\Console\Scheduling\Event`).

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.scheduler` | `METRICS_SCHEDULER` | `true` |
