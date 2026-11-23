# Changelog

## 1.1.0 - 2026-11-23

- Laravel Octane is supported. Requests, tasks and ticks run through Octane's worker in the tests.
- Fixed, under Octane: metrics recorded in a request before any built-in listener ran, such as a custom counter at the start of the first request, went to a meter provider that no flush read, and were lost. The worker now builds one meter provider when it starts.
- Fixed, under Octane: `laravel.db.queries` counted the queries that ran after the previous response, or while the worker booted, for the next request. The count now starts when the previous request, task or tick has ended, so queries of an application's own `RequestReceived` listeners count for their request.
- Fixed, under Octane: tasks and ticks never merged their metrics. They now merge when they end, including a task or tick that fails, and a stopping worker merges what is left. A request also merges what its own `terminating` callbacks record.
- Fixed: with the `cache` store on the `database` cache driver, the database instrumentation recorded the store's own queries, and the next flush merged them. In a queue worker or an Octane worker, every flush added queries to `db.client.operation.duration`. The store's queries are no longer recorded.
- Fixed: a connection purged and built again for another database, such as a tenant connection, kept the `db.namespace`, `server.address` and `server.port` of the first database. Under Octane this lasted for the life of the worker. The attributes are now computed per connection object.

## 1.0.0 - 2026-11-23

First stable release, with no code changes since 0.3.0. From this version on, the public API follows semantic versioning: the `Metrics` facade, `Metrics::fake()`, the configuration keys, the `metrics:push` command and the names and attributes of the built-in metrics.

- Requires `nwidart/metrics-store` `^1.0`.

## 0.3.0 - 2026-11-22

- Added database and Eloquent metrics:
  - `laravel.db.transaction.duration`, per outer transaction, with `laravel.db.transaction.result` = `committed` or `rolled_back`;
  - `laravel.db.queries`, the number of queries per HTTP request and per queued job;
  - `laravel.eloquent.models`, models retrieved, created, updated, deleted and restored, per model class, and `laravel.eloquent.models.pruned`. Switch: `instrumentation.eloquent` (`METRICS_ELOQUENT`);
  - read on export: `laravel.db.server.connections` and `laravel.db.server.connections.max` of the default connection's server (`instrumentation.db_server`, `METRICS_DB_SERVER`), and `laravel.db.migrations.pending` (`instrumentation.migrations`, `METRICS_MIGRATIONS`).
- A `config/metrics.php` published by an older version gets the default of each `instrumentation` switch it lacks, so new instrumentation is on after an upgrade.
- Fixed: the `Metrics` facade did not declare `exclude()` and `export()`, so IDEs and static analysis flagged them.

## 0.2.0 - 2026-11-22

- Added `Metrics::export()`, what the scrape and `metrics:push` send, and `Metrics::exclude()` to leave metrics out of every export.
- Fixed: an export sent the store's values while metrics were disabled. It now sends nothing. This affected packages that serve the Prometheus controller under their own route, such as laravel-actuator.
- Fixed: a store that could not be built (an unknown `METRICS_STORE`) failed every request and made the scrape answer 500. It is now reported once, nothing is recorded, and the scrape answers 503.

## 0.1.0 - 2026-11-22

First release.

- Records through the OpenTelemetry SDK with delta temporality, and merges each request, command and queued job into a shared store after the response.
- Built-in instrumentation, each with its own switch:
  - `http.server.request.duration`;
  - `db.client.operation.duration`;
  - `messaging.process.duration`, including attempts stopped at their timeout, and `messaging.client.sent.messages`;
  - `laravel.cache.operations`;
  - `laravel.scheduler.task.duration`;
  - `laravel.exceptions`.
- Metrics read on export: `laravel.info`, OPcache, PHP-FPM, load average and disk usage.
- `metrics.ignore_routes`: routes, by name or URI template, that `http.server.request.duration` leaves out, such as health probes.
- `GET /metrics` in the Prometheus text format, off by default.
- `php artisan metrics:push` to an OTLP/HTTP endpoint.
- Stores:
  - `redis` for production;
  - `cache`, a Laravel cache store, for development without Redis.
- `Metrics` facade: `meter()`, `attributes()` to add attributes to the built-in metrics, `onExport()` and `flush()`.
- `Metrics::fake()` for tests.
