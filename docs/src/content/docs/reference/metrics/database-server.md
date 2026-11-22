---
title: Database server and migrations
description: The connections of the database server and the pending migrations, read on each export.
---

`Nwidart\Metrics\Instrumentation\DatabaseServerMetrics` and `MigrationMetrics` create observable instruments when the metrics are exported, like the [metrics read on export](/laravel-metrics/reference/metrics/export-time/). The values are never stored. Each one runs a query on the default connection during the export.

## Instruments

Every instrument belongs to the meter `nwidart/laravel-metrics`.

| Name | Kind | Unit | Description | Prometheus name |
|---|---|---|---|---|
| `laravel.db.server.connections` | Observable up-down counter | `{connection}` | `Connections open on the database server.` | `laravel_db_server_connections` |
| `laravel.db.server.connections.max` | Observable up-down counter | `{connection}` | `Most connections the database server accepts.` | `laravel_db_server_connections_max` |
| `laravel.db.migrations.pending` | Observable up-down counter | `{migration}` | `Migrations that have not run yet.` | `laravel_db_migrations_pending` |

## Attributes

The two connection metrics have the attributes of [`db.client.operation.duration`](/laravel-metrics/reference/metrics/database/#attributes) that describe the connection: `db.system.name`, `db.namespace`, `server.address` and `server.port`. `laravel.db.migrations.pending` has no attribute.

## Recorded when

| Metric | Present when | Source |
|---|---|---|
| `laravel.db.server.connections` | The default connection is MySQL, MariaDB or PostgreSQL | `Threads_connected` on MySQL and MariaDB. On PostgreSQL, the rows of `pg_stat_activity` whose `backend_type` is `client backend` |
| `laravel.db.server.connections.max` | The default connection is MySQL, MariaDB or PostgreSQL | `@@max_connections`, or `show max_connections` on PostgreSQL |
| `laravel.db.migrations.pending` | Always | The migrations `php artisan migrate` would run, from the same paths |

The connections count every client of the server, not only this application. Under PHP-FPM, each busy worker holds one connection, so a server at its maximum refuses the next request's.

`laravel.db.migrations.pending` leaves out the migrations that `migrate` skips: those of `Migrator::withoutMigrations()`, and those whose `shouldRun()` returns `false`. Above 0 after a deploy, the code expects a schema the database does not have yet.

## Not recorded

- SQL Server and SQLite connections. SQL Server's session count leaves out idle sessions, and its `@@max_connections` is not the configured limit.
- Any of the three metrics while the database cannot be reached. The export leaves them out and reports no error.

## Switches

| Key | Variable | Default | Metrics |
|---|---|---|---|
| `instrumentation.db_server` | `METRICS_DB_SERVER` | `true` | `laravel.db.server.connections`, `laravel.db.server.connections.max` |
| `instrumentation.migrations` | `METRICS_MIGRATIONS` | `true` | `laravel.db.migrations.pending` |

Both are registered only when the application binds `db` (and `migrator` for the migrations), so an application without `illuminate/database` runs no query.
