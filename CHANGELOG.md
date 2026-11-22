# Changelog

## Unreleased

- Added database and Eloquent metrics:
  - `laravel.db.transaction.duration`, per outer transaction, with `laravel.db.transaction.result` = `committed` or `rolled_back`;
  - `laravel.db.queries`, the number of queries per HTTP request and per queued job;
  - `laravel.eloquent.models`, models retrieved, created, updated, deleted and restored, per model class, and `laravel.eloquent.models.pruned`. Switch: `instrumentation.eloquent` (`METRICS_ELOQUENT`);
  - read on export: `laravel.db.server.connections` and `laravel.db.server.connections.max` of the default connection's server (`instrumentation.db_server`, `METRICS_DB_SERVER`), and `laravel.db.migrations.pending` (`instrumentation.migrations`, `METRICS_MIGRATIONS`).
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
