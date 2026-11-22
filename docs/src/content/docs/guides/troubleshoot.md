---
title: Troubleshoot
description: Find and fix the cause when metrics are missing, the scrape fails, a push fails, or values look wrong.
---

Find your symptom below. Each section lists the checks in the order to run them.

Most fixes change a variable in `.env`. After each change, run `php artisan config:cache` if the configuration is cached, and restart the queue workers.

## /metrics answers 404

1. **The route is off.** Set `METRICS_PROMETHEUS=true`. The route is off by default.
2. **Metrics are off.** With `METRICS_ENABLED=false`, the route is not registered. Check with `php artisan config:show metrics`.
3. **The routes are cached.** With cached routes, the package does not register the route itself. After you change `METRICS_PROMETHEUS` or `METRICS_PROMETHEUS_PATH`, run `php artisan config:cache`, then `php artisan route:cache`. The route cache is built from the cached configuration.
4. **The path is another one.** Check `METRICS_PROMETHEUS_PATH`, and look for the route:

   ```bash
   php artisan route:list --name=metrics.prometheus
   ```

## /metrics answers 503

The store cannot be built or read. The cause is reported through the exception handler, so look in the application log.

- **Unknown driver.** `METRICS_STORE` must be `redis` or `cache`. The log says `Unknown metrics store driver`.
- **Redis is unreachable.** Check the connection in `METRICS_REDIS_CONNECTION`:

  ```bash
  php artisan tinker --execute="Redis::connection(config('metrics.store.connection'))->ping()"
  ```

- **A phpredis cluster connection.** It is not supported. The log says `The metrics store needs a phpredis or predis connection`. Use a connection to one Redis instance.
- **A cache driver without locks.** With `METRICS_STORE=cache`, the cache store must support locks. `apc` does not.

## /metrics answers, but shows nothing

1. **Metrics are off, and the route is your own or cached.** With `METRICS_ENABLED=false`, every export is empty, even if the store still holds values.
2. **Nothing was recorded yet.** Send a request to another route, then scrape again. With `ignore_routes`, ignored routes record nothing.
3. **The web servers write to another store.** Compare `METRICS_STORE`, `METRICS_REDIS_CONNECTION` and `METRICS_PREFIX` of the process that records and the process that answers the scrape.
4. **The cache store uses the `array` driver.** It keeps the store for one process only. Use `file`, `database` or `redis`. See [Develop without Redis](/laravel-metrics/guides/develop-without-redis/).
5. **The store could not be built in the recording process.** That process records nothing and reports the error once. Look in the log.

## A metric is missing

- **Its instrumentation is off.** Check `METRICS_HTTP`, `METRICS_DB`, `METRICS_QUEUE`, `METRICS_CACHE`, `METRICS_SCHEDULER`, `METRICS_EXCEPTIONS` and `METRICS_RUNTIME`.
- **A filter leaves it out.** Look for `Metrics::exclude()` calls.
- **The event never happens.** Each built-in metric has cases it does not record, such as failed queries or unreported exceptions. See the page of the metric under [Built-in metrics](/laravel-metrics/reference/metrics/http/).
- **The command or daemon has not ended.** A long command merges when it ends. Call `Metrics::flush()`. See [Flush in long-running work](/laravel-metrics/guides/flush-long-running-work/).
- **OPcache metrics in `metrics:push`.** OPcache is off on the command line by default, so a push has no OPcache metrics. Scrape over the web server to get them.

## metrics:push exits with 1

The command prints the cause.

- **`The metrics store cannot be read`**: see [/metrics answers 503](#metrics-answers-503). The causes are the same.
- **`The OTLP endpoint did not accept the metrics`**:
  1. Check the URL. The package appends `/v1/metrics`, so `OTEL_EXPORTER_OTLP_ENDPOINT` must not end with it.
  2. Check that the endpoint speaks OTLP over HTTP, usually port 4318. Port 4317 is gRPC, which the package does not send.
  3. Check the protocol. Some backends accept only `http/protobuf`, the default.
  4. Check the headers in `metrics.push.headers`, such as the API key.
  5. Check the timeout, `metrics.push.timeout`, 10 seconds by default.

The OTLP exporter logs the reason, such as the HTTP status, through the OpenTelemetry logger: the PHP error log by default, not the Laravel log.

## "Set metrics.push.endpoint (OTEL_EXPORTER_OTLP_ENDPOINT) to push metrics."

`metrics:push` has something to send but no endpoint.

1. Set `OTEL_EXPORTER_OTLP_ENDPOINT` in `.env`.
2. If the configuration is cached, run `php artisan config:cache` again. A cached configuration does not read `.env`.
3. Check that the scheduler process has the variable, for example in its container or its systemd unit.

## Counters reset

Backends show a reset when the store starts again from empty. Look for one of these:

- **Redis evicted keys.** The store's Redis must have `maxmemory-policy noeviction`. Check with `redis-cli CONFIG GET maxmemory-policy`.
- **Redis restarted without persistence.** Turn on RDB or AOF persistence on the store's Redis.
- **Someone cleared it.** `FLUSHDB` or `FLUSHALL` on that Redis clear the store. With the `cache` store, `php artisan cache:clear` on its cache store does too.
- **The prefix or the connection changed.** A new `METRICS_PREFIX` or another Redis starts an empty store.

Rate functions, such as `rate()` in Prometheus, handle a reset.

## Values come from one server only

- **The servers write to different stores.** Give every server the same Redis, `REDIS_PREFIX` and `METRICS_PREFIX`. See [Run several applications on one Redis](/laravel-metrics/guides/share-a-redis/).
- **The cache store uses the `file` driver.** Each server then has its own file. Use the `redis` store in production.
- **It is a metric read on export.** OPcache, PHP-FPM, load average and disk usage describe the server that answers the export. This is expected.

## Values show twice as high

Prometheus scrapes more than one address. Every address serves the same store, so a sum across targets counts each value once per target. Scrape one address only. See [Scrape with Prometheus](/laravel-metrics/guides/scrape-with-prometheus/).

## Warnings in the PHP error log

A merge that fails writes a warning through the OpenTelemetry logger. By default, it goes to the PHP error log. The request goes on, and the metrics of that merge are lost.

- **Redis is unreachable or slow.** Check the store's Redis, as for [/metrics answers 503](#metrics-answers-503).
- **The cache store waited for its lock.** A flush that waits more than 1 second for the lock is dropped. Use the `redis` store under load.
- **A phpredis cluster connection.** It is not supported.

## A series has `otel_metric_overflow="true"`

A metric reached the cardinality limit: 2000 series by default. Every new attribute set now goes to one overflow series.

1. Find the attribute with many values. In Prometheus:

   ```text
   count by (__name__) ({__name__=~".+", otel_metric_overflow="true"})
   ```

   Then count the values of each label of that metric, for example:

   ```text
   count(count by (http_route) (http_server_request_duration_seconds_count))
   ```

2. Bound the attribute. Replace IDs, URLs or user input with a value from a small set. For a built-in attribute, override it with `Metrics::attributes()`. See [Add attributes to built-in metrics](/laravel-metrics/guides/add-attributes/).
3. If the values are legitimate, raise `cardinality_limit` in `config/metrics.php`.

The overflow series and the old series stay in the store until it is cleared.

## Related

- [Configuration reference](/laravel-metrics/reference/configuration/)
- [Limits](/laravel-metrics/reference/limits/)
- [Failure handling](/laravel-metrics/explanation/failure-handling/)
