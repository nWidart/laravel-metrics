---
title: Metrics read on export
description: The Laravel, PHP, OPcache, PHP-FPM and system metrics that each export reads and never stores.
---

`Nwidart\Metrics\Instrumentation\RuntimeMetrics` creates observable instruments when the metrics are exported: on a scrape of the [Prometheus endpoint](/laravel-metrics/reference/prometheus-endpoint/), and on [`metrics:push`](/laravel-metrics/reference/metrics-push/). The values are never stored. They describe the process and the server that run the export, not the whole application.

The package registers it through [`Metrics::onExport()`](/laravel-metrics/reference/facade/#onexport). Requests keep the callback and create no instrument.

## Instruments

Every instrument belongs to the meter `nwidart/laravel-metrics`.

| Name | Kind | Unit | Description |
|---|---|---|---|
| `laravel.info` | Observable gauge | None | `Laravel and PHP versions of the process that exports the metrics.` |
| `php.opcache.memory.usage` | Observable up-down counter | `By` | `OPcache shared memory, by state.` |
| `php.opcache.scripts` | Observable up-down counter | `{script}` | `Scripts held in OPcache.` |
| `php.opcache.lookups` | Observable counter | `{lookup}` | `OPcache lookups, by result.` |
| `php.fpm.processes` | Observable up-down counter | `{process}` | `PHP-FPM worker processes of the pool, by state.` |
| `php.fpm.listen_queue` | Observable up-down counter | `{request}` | `Requests waiting for a free PHP-FPM worker.` |
| `php.fpm.connections.accepted` | Observable counter | `{connection}` | `Requests accepted by the PHP-FPM pool.` |
| `php.fpm.max_children_reached` | Observable counter | `{event}` | `Times the PHP-FPM pool reached its process limit.` |
| `php.fpm.slow_requests` | Observable counter | `{request}` | `Requests slower than the PHP-FPM slow log timeout.` |
| `system.cpu.load_average.1m` | Observable gauge | `{thread}` | `Average number of threads ready or running over the last minute.` |
| `system.filesystem.usage` | Observable up-down counter | `By` | `Space usage of the disk that holds the application.` |

No instrument has histogram buckets.

## Attributes

| Metric | Attribute | Values |
|---|---|---|
| `laravel.info` | `laravel.version` | The Laravel version, such as `12.40.0` |
| | `process.runtime.name` | `php` |
| | `process.runtime.version` | `PHP_VERSION`, such as `8.4.27` |
| | `php.sapi` | `PHP_SAPI`, such as `fpm-fcgi` or `cli` |
| `php.opcache.memory.usage` | `php.opcache.memory.state` | `used`, `free`, `wasted` |
| `php.opcache.lookups` | `php.opcache.lookup.result` | `hit`, `miss` |
| `php.fpm.processes` | `php.fpm.process.state` | `active`, `idle` |
| `system.filesystem.usage` | `system.filesystem.state` | `used`, `free` |

The other metrics have no attribute. `laravel.info` always has the value `1`.

## Recorded when

Each export runs the callback once, on a meter provider that exists only during the export. The instruments are read when the provider shuts down.

| Metrics | Present when | Source |
|---|---|---|
| `laravel.info` | Always | `Application::version()`, `PHP_VERSION`, `PHP_SAPI` |
| `php.opcache.*` | OPcache runs in the exporting process | `opcache_get_status(false)` |
| `php.fpm.*` | The export runs under PHP-FPM | `fpm_get_status()` |
| `system.cpu.load_average.1m` | `sys_getloadavg()` exists and succeeds (not on Windows) | `sys_getloadavg()[0]` |
| `system.filesystem.usage` | The disk that holds the application base path can be read | `disk_free_space()`, `disk_total_space()` |

A value that the source does not return as an integer is left out. `php.opcache.scripts` is created only when `num_cached_scripts` is an integer.

OPcache is off on the command line by default, and the command line has no PHP-FPM status. A `metrics:push` export therefore usually holds `laravel.info`, `system.cpu.load_average.1m` and `system.filesystem.usage` only.

## Prometheus names

| Metric | Prometheus name | Type |
|---|---|---|
| `laravel.info` | `laravel_info` | `gauge` |
| `php.opcache.memory.usage` | `php_opcache_memory_usage_bytes` | `gauge` |
| `php.opcache.scripts` | `php_opcache_scripts` | `gauge` |
| `php.opcache.lookups` | `php_opcache_lookups_total` | `counter` |
| `php.fpm.processes` | `php_fpm_processes` | `gauge` |
| `php.fpm.listen_queue` | `php_fpm_listen_queue` | `gauge` |
| `php.fpm.connections.accepted` | `php_fpm_connections_accepted_total` | `counter` |
| `php.fpm.max_children_reached` | `php_fpm_max_children_reached_total` | `counter` |
| `php.fpm.slow_requests` | `php_fpm_slow_requests_total` | `counter` |
| `system.cpu.load_average.1m` | `system_cpu_load_average_1m` | `gauge` |
| `system.filesystem.usage` | `system_filesystem_usage_bytes` | `gauge` |

## Not recorded

- While metrics are [disabled](/laravel-metrics/reference/configuration/#enabled), no callback runs.
- Metrics of the exporting process itself, such as CPU time or memory.
- Values of other servers or processes.

## Attribute contributor context

None. [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callbacks do not apply to these metrics.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.runtime` | `METRICS_RUNTIME` | `true` |

The switch turns off only these metrics. Callbacks added with `Metrics::onExport()` still run.
