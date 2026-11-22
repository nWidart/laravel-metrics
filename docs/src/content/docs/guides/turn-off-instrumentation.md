---
title: Turn off instrumentation
description: Stop recording one built-in instrumentation, or turn off metrics as a whole for an environment.
---

## Turn off one instrumentation

Each built-in instrumentation has its own switch. To stop recording cache operations, for example:

```bash title=".env"
METRICS_CACHE=false
```

| Variable | Stops |
|---|---|
| `METRICS_HTTP` | `http.server.request.duration` |
| `METRICS_DB` | `db.client.operation.duration` |
| `METRICS_QUEUE` | `messaging.process.duration` and `messaging.client.sent.messages` |
| `METRICS_CACHE` | `laravel.cache.operations` |
| `METRICS_SCHEDULER` | `laravel.scheduler.task.duration` |
| `METRICS_EXCEPTIONS` | `laravel.exceptions` |
| `METRICS_RUNTIME` | The metrics read on export: `laravel.info`, OPcache, PHP-FPM, load average, disk usage |

If the configuration is cached, run `php artisan config:cache` again. Then restart the queue workers with `php artisan queue:restart`, or `php artisan horizon:terminate` with Horizon.

The series recorded before the switch stay in the store and are still exported. To hide them, see [Leave metrics out of exports](/laravel-metrics/guides/exclude-metrics/).

## Turn off metrics as a whole

```bash title=".env"
METRICS_ENABLED=false
```

With metrics off:

- nothing is recorded, and nothing touches the store;
- no listener and no scrape route are registered;
- every export is empty, even if the store still holds older values.

`metrics:push` still runs, and prints `The store holds no metrics.`

## Turn off metrics in tests

To keep tests away from the store, set the variable in `phpunit.xml`:

```xml title="phpunit.xml"
<php>
    <env name="METRICS_ENABLED" value="false"/>
</php>
```

`Metrics::fake()` needs metrics on. To assert metrics in some tests, keep metrics on and use an in-memory cache instead. See [Test your metrics](/laravel-metrics/guides/test-metrics/).

## Related

- [Configuration reference](/laravel-metrics/reference/configuration/)
- [Built-in metrics](/laravel-metrics/reference/metrics/http/)
- [Reduce the overhead](/laravel-metrics/guides/reduce-overhead/)
