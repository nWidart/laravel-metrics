# Changelog

## 1.0.0

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
- `GET /metrics` in the Prometheus text format, off by default.
- `php artisan metrics:push` to an OTLP/HTTP endpoint.
- Stores:
  - `redis` for production;
  - `cache`, a Laravel cache store, for development without Redis.
- `Metrics` facade: `meter()`, `attributes()` to add attributes to the built-in metrics, `onExport()` and `flush()`.
- `Metrics::fake()` for tests.
