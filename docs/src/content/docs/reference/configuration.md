---
title: Configuration
description: Every key of config/metrics.php, its environment variable, its default and its effect.
---

The package reads every value through `config/metrics.php`. It reads no `OTEL_*` variable directly, so it works with `php artisan config:cache`.

Publish the file to change the defaults:

```bash
php artisan vendor:publish --tag=metrics-config
```

The keys below are in the order of the file.

## Summary

| Key | Variable | Default |
|---|---|---|
| [`enabled`](#enabled) | `METRICS_ENABLED` | `true` |
| [`store.driver`](#storedriver) | `METRICS_STORE` | `redis` |
| [`store.connection`](#storeconnection) | `METRICS_REDIS_CONNECTION` | `default` |
| [`store.prefix`](#storeprefix) | `METRICS_PREFIX` | `{metrics}:` |
| [`store.cache_store`](#storecache_store) | `METRICS_CACHE_STORE` | `null` |
| [`cardinality_limit`](#cardinality_limit) | None | `2000` |
| [`register_global`](#register_global) | `METRICS_REGISTER_GLOBAL` | `true` |
| [`prometheus.enabled`](#prometheusenabled) | `METRICS_PROMETHEUS` | `false` |
| [`prometheus.path`](#prometheuspath) | `METRICS_PROMETHEUS_PATH` | `metrics` |
| [`prometheus.middleware`](#prometheusmiddleware) | None | `[]` |
| [`prometheus.labels`](#prometheuslabels) | None | `[]` |
| [`push.endpoint`](#pushendpoint) | `OTEL_EXPORTER_OTLP_ENDPOINT` | `null` |
| [`push.protocol`](#pushprotocol) | `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` |
| [`push.headers`](#pushheaders) | None | `[]` |
| [`push.timeout`](#pushtimeout) | None | `10` |
| [`push.resource`](#pushresource) | `OTEL_SERVICE_NAME`, `APP_NAME`, `APP_ENV` | See below |
| [`ignore_routes`](#ignore_routes) | None | `[]` |
| [`instrumentation.*`](#instrumentation) | `METRICS_HTTP`, `METRICS_DB`, `METRICS_ELOQUENT`, `METRICS_QUEUE`, `METRICS_CACHE`, `METRICS_SCHEDULER`, `METRICS_EXCEPTIONS`, `METRICS_RUNTIME`, `METRICS_DB_SERVER`, `METRICS_MIGRATIONS` | `true` |

## `enabled`

- Variable: `METRICS_ENABLED`
- Type: boolean
- Default: `true`

With `false`:

- The meter provider is a no-op provider. `Metrics::meter()` instruments record nothing.
- No listener, no `terminating` flush and no Prometheus route is registered.
- The meter provider is not registered as the OpenTelemetry global.
- [`Metrics::export()`](/laravel-metrics/reference/facade/#export) returns an empty list, so the scrape and `metrics:push` send nothing, even if the store still holds older values.

The `metrics-config` publish tag and the `metrics:push` command stay registered.

## `store.driver`

- Variable: `METRICS_STORE`
- Type: string
- Default: `redis`
- Values: `redis`, `cache`

The store that every flush merges into and every export reads. See [Stores](/laravel-metrics/reference/stores/).

Any other value throws `InvalidArgumentException` when the store is built:

```text
Unknown metrics store driver [<value>]: use redis or cache.
```

## `store.connection`

- Variable: `METRICS_REDIS_CONNECTION`
- Type: string
- Default: `default`

The Redis connection from `config/database.php` that the `redis` driver uses. The connection's own key prefix is kept.

## `store.prefix`

- Variable: `METRICS_PREFIX`
- Type: string
- Default: `{metrics}:`

The key prefix of the store. The `redis` driver puts it after the connection's own prefix. The `cache` driver uses `<prefix>state` as its cache key. The braces are a Redis Cluster hash tag. A value that is not a string falls back to `{metrics}:`.

## `store.cache_store`

- Variable: `METRICS_CACHE_STORE`
- Type: string or `null`
- Default: `null` (the default cache store)

The cache store from `config/cache.php` that the `cache` driver uses. `null` and an empty string select the default cache store.

## `cardinality_limit`

- Variable: none
- Type: integer
- Default: `2000`

The maximum number of series per metric in the store. Extra attribute sets go to one overflow series with the attribute `otel.metric.overflow = true`. A value that is not numeric falls back to `2000`. See [Cardinality](/laravel-metrics/explanation/cardinality/).

## `register_global`

- Variable: `METRICS_REGISTER_GLOBAL`
- Type: boolean
- Default: `true`

With `true`, the package's meter provider becomes the OpenTelemetry global (`OpenTelemetry\API\Globals::meterProvider()`), through `Globals::registerInitializer()`. Libraries that record through the OpenTelemetry API then record into the store.

## `prometheus.enabled`

- Variable: `METRICS_PROMETHEUS`
- Type: boolean
- Default: `false`

With `true`, the package registers the scrape route. See [Prometheus endpoint](/laravel-metrics/reference/prometheus-endpoint/).

## `prometheus.path`

- Variable: `METRICS_PROMETHEUS_PATH`
- Type: string
- Default: `metrics`

The URI of the scrape route. A value that is not a string falls back to `metrics`.

## `prometheus.middleware`

- Variable: none
- Type: list of strings, or one string
- Default: `[]`

Middleware of the scrape route: aliases, group names or class names. Values that are not strings are dropped.

```php title="config/metrics.php"
'prometheus' => [
    // ...
    'middleware' => ['auth.basic'],
],
```

## `prometheus.labels`

- Variable: none
- Type: array of label name to scalar value
- Default: `[]`

Labels added to every sample of the scrape. A boolean becomes `true` or `false`. Values that are not scalar are dropped.

```php title="config/metrics.php"
'prometheus' => [
    // ...
    'labels' => ['service' => 'shop'],
],
```

## `push.endpoint`

- Variable: `OTEL_EXPORTER_OTLP_ENDPOINT`
- Type: string
- Default: `null`

The OTLP/HTTP endpoint of `metrics:push`. The package removes a trailing `/` and appends `/v1/metrics`: `http://collector:4318` sends to `http://collector:4318/v1/metrics`.

A missing or empty endpoint throws `InvalidArgumentException` when `metrics:push` has metrics to send:

```text
Set metrics.push.endpoint (OTEL_EXPORTER_OTLP_ENDPOINT) to push metrics.
```

## `push.protocol`

- Variable: `OTEL_EXPORTER_OTLP_PROTOCOL`
- Type: string
- Default: `http/protobuf`
- Values: `http/protobuf`, `http/json`

`http/json` sends JSON. Any other value sends protobuf.

## `push.headers`

- Variable: none
- Type: array of header name to string
- Default: `[]`

HTTP headers of each push, such as an API key. Values that are not strings are dropped.

```php title="config/metrics.php"
'push' => [
    // ...
    'headers' => ['x-api-key' => env('METRICS_PUSH_API_KEY')],
],
```

## `push.timeout`

- Variable: none
- Type: number, in seconds
- Default: `10`

The timeout of each push. A value that is not numeric falls back to `10`.

## `push.resource`

- Type: array of attribute name to scalar value
- Default:

```php title="config/metrics.php"
'resource' => [
    'service.name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel')),
    'deployment.environment.name' => env('APP_ENV'),
],
```

The resource attributes of each push. Values that are not scalar, and empty strings, are dropped. The resource holds only these attributes: no host, process or SDK attribute is added. The Prometheus scrape does not use the resource.

## `ignore_routes`

- Variable: none
- Type: list of patterns
- Default: `[]`

Routes that `http.server.request.duration` does not record.

- A pattern that starts with `/` matches the URI template, such as `/up` or `/users/{user}`.
- Any other pattern matches the route name, such as `horizon.*`. A route without a name matches no name pattern.
- `*` is a wildcard. Matching uses `Illuminate\Support\Str::is()`.
- Values that are not strings are dropped.

A request without a matched route, such as a 404, is never ignored.

```php title="config/metrics.php"
'ignore_routes' => ['/up', 'horizon.*'],
```

## `instrumentation`

One boolean switch per built-in instrumentation. `false` registers none of its listeners.

| Key | Variable | Default | Metrics |
|---|---|---|---|
| `instrumentation.http` | `METRICS_HTTP` | `true` | [`http.server.request.duration`](/laravel-metrics/reference/metrics/http/) |
| `instrumentation.db` | `METRICS_DB` | `true` | [`db.client.operation.duration`](/laravel-metrics/reference/metrics/database/), [`laravel.db.transaction.duration`](/laravel-metrics/reference/metrics/transactions/), [`laravel.db.queries`](/laravel-metrics/reference/metrics/query-count/) |
| `instrumentation.eloquent` | `METRICS_ELOQUENT` | `true` | [`laravel.eloquent.models`, `laravel.eloquent.models.pruned`](/laravel-metrics/reference/metrics/eloquent/) |
| `instrumentation.queue` | `METRICS_QUEUE` | `true` | [`messaging.process.duration`, `messaging.client.sent.messages`](/laravel-metrics/reference/metrics/queue/) |
| `instrumentation.cache` | `METRICS_CACHE` | `true` | [`laravel.cache.operations`](/laravel-metrics/reference/metrics/cache/) |
| `instrumentation.scheduler` | `METRICS_SCHEDULER` | `true` | [`laravel.scheduler.task.duration`](/laravel-metrics/reference/metrics/scheduler/) |
| `instrumentation.exceptions` | `METRICS_EXCEPTIONS` | `true` | [`laravel.exceptions`](/laravel-metrics/reference/metrics/exceptions/) |
| `instrumentation.runtime` | `METRICS_RUNTIME` | `true` | [Metrics read on export](/laravel-metrics/reference/metrics/export-time/) |
| `instrumentation.db_server` | `METRICS_DB_SERVER` | `true` | [`laravel.db.server.connections`, `.max`](/laravel-metrics/reference/metrics/database-server/), read on export |
| `instrumentation.migrations` | `METRICS_MIGRATIONS` | `true` | [`laravel.db.migrations.pending`](/laravel-metrics/reference/metrics/database-server/), read on export |

The flush after each queued job does not depend on `instrumentation.queue`.

A `config/metrics.php` published by an older version keeps its own `instrumentation` array. Each switch it lacks takes its default, so new instrumentation is on after an upgrade.
