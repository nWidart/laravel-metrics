---
title: Flush in long-running work
description: Merge metrics into the store while a daemon or a long command still runs, instead of only when it ends.
---

The package flushes when a request or a command terminates, and after each queued job. A command that runs for hours merges nothing until it ends. To merge sooner, call `Metrics::flush()`.

## Flush after each unit of work

```php title="app/Console/Commands/ConsumeEvents.php"
use Nwidart\Metrics\Facades\Metrics;

public function handle(EventStream $stream): void
{
    foreach ($stream->messages() as $message) {
        $this->process($message);

        Metrics::flush();
    }
}
```

A flush merges what was recorded since the previous one. With the `redis` store, a flush with nothing recorded makes no call to Redis.

## Flush on a timer

When units of work are small and frequent, a flush after each one costs one store call each. Flush every few seconds instead:

```php
$lastFlush = microtime(true);

while (true) {
    $this->processNext();

    if (microtime(true) - $lastFlush >= 10) {
        Metrics::flush();
        $lastFlush = microtime(true);
    }
}
```

## What you do not need to flush

- **Requests.** Each one flushes after the response is sent.
- **Commands that end.** Each one flushes when it terminates.
- **Queue workers and Horizon.** A worker flushes after each job, including a job that fails or times out.
- **Laravel Octane.** Each request flushes when it terminates. Tasks and ticks flush when they end, and a stopping worker flushes what is left.
- **Sync jobs.** They merge with the request or the command that dispatched them.

## On failure

A flush never throws. If the store cannot be reached, a warning goes to the OpenTelemetry logger, the PHP error log by default, and the metrics of that flush are lost.

## Related

- [Metrics facade reference](/laravel-metrics/reference/facade/)
- [How it works](/laravel-metrics/explanation/how-it-works/)
- [Failure handling](/laravel-metrics/explanation/failure-handling/)
