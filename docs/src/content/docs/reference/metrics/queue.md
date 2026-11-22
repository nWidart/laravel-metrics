---
title: Queue jobs
description: The messaging.process.duration histogram and the messaging.client.sent.messages counter, their attributes and when they are recorded.
---

`Nwidart\Metrics\Instrumentation\QueueMetrics` records queued jobs, as the OpenTelemetry messaging semantic conventions define them. It has two instruments.

## Instruments

### `messaging.process.duration`

| Property | Value |
|---|---|
| Name | `messaging.process.duration` |
| Kind | Histogram |
| Unit | `s` |
| Buckets | `0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10` |
| Description | `Duration of processing operation.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `messaging_process_duration_seconds` |

One value per attempt, from `JobProcessing` to the end of the attempt.

### `messaging.client.sent.messages`

| Property | Value |
|---|---|
| Name | `messaging.client.sent.messages` |
| Kind | Counter |
| Unit | `{message}` |
| Buckets | None |
| Description | `Number of messages producer attempted to send to the broker.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `messaging_client_sent_messages_total` |

One per job pushed to a queue.

## Attributes

### `messaging.process.duration`

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `messaging.system` | string | See [`messaging.system`](#messagingsystem) | Always |
| `messaging.operation.name` | string | `process` | Always |
| `laravel.job.name` | string | The job name (`Job::resolveName()`), such as `App\Jobs\ShipOrder` | Always |
| `messaging.destination.name` | string | The queue name, such as `default` | The job's queue name is not empty |
| `error.type` | string | See below | The attempt did not succeed |

`error.type` values:

| Attempt | `error.type` |
|---|---|
| The job threw an exception | The exception class, such as `RuntimeException` |
| The job failed itself (`$this->fail()`), or had no attempts left | `failed` |
| The worker stopped the job at its timeout | `Illuminate\Queue\TimeoutExceededException` |

### `messaging.client.sent.messages`

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `messaging.system` | string | See [`messaging.system`](#messagingsystem) | Always |
| `messaging.operation.name` | string | `send` | Always |
| `laravel.job.name` | string | See below | Always |
| `messaging.destination.name` | string | The queue of the event, else `queue.connections.<connection>.queue` | The queue name is not empty |

`laravel.job.name` is the job's `displayName()` when it has one that returns a string, else the job class, else the job string, else `unknown`.

### `messaging.system`

The `driver` of the queue connection in `config/queue.php`, or the connection name when the connection has no driver. One driver has another name:

| Laravel driver | `messaging.system` |
|---|---|
| `sqs` | `aws_sqs` |
| Any other, such as `redis`, `database`, `beanstalkd`, `sync` | The driver name |

The value is computed once per connection name and process.

## Recorded when

| Event | Effect |
|---|---|
| `Illuminate\Queue\Events\JobQueued` | Adds 1 to `messaging.client.sent.messages` |
| `Illuminate\Queue\Events\JobProcessing` | Starts the timer of the attempt |
| `Illuminate\Queue\Events\JobProcessed` | Records the attempt, with `error.type` = `failed` when the job has failed |
| `Illuminate\Queue\Events\JobExceptionOccurred` | Records the attempt, with the exception class |
| `Illuminate\Queue\Events\JobTimedOut` | Records the attempt, with `Illuminate\Queue\TimeoutExceededException` |

Each attempt is recorded once. An end event without a matching `JobProcessing` records nothing.

After `JobProcessed`, `JobExceptionOccurred`, `JobFailed` and `JobTimedOut`, the package [flushes](/laravel-metrics/reference/facade/#flush), except for a job of the `sync` queue. A `sync` job merges with the request or command that dispatched it.

## Not recorded

- `JobFailed` records no value. The attempt that led to it is already recorded.

## Attribute contributor context

| Metric | The callback receives |
|---|---|
| `messaging.process.duration` | `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut`, with `$event->job` and `$event->connectionName` |
| `messaging.client.sent.messages` | `JobQueued`, with `$event->job`, `$event->queue` and `$event->connectionName` |

See [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes).

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.queue` | `METRICS_QUEUE` | `true` |

The flush after each job does not depend on this switch.
