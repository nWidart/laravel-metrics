---
title: Database queries
description: The db.client.operation.duration histogram, its attributes and when it is recorded.
---

`Nwidart\Metrics\Instrumentation\DatabaseClientMetrics` records the duration of each successful query, as the OpenTelemetry database semantic conventions define it.

## Instrument

| Property | Value |
|---|---|
| Name | `db.client.operation.duration` |
| Kind | Histogram |
| Unit | `s` |
| Buckets | `0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10` |
| Description | `Duration of database client operations.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `db_client_operation_duration_seconds` |

The duration is the query time that Laravel measures (`QueryExecuted::$time`, in milliseconds), divided by 1000.

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `db.system.name` | string | See the table below | Always |
| `db.namespace` | string | The database name of the connection | The database name is not empty |
| `db.operation.name` | string | See the list below | The first keyword of the query is in the list |
| `server.address` | string | The `host` of the connection | The connection has a `host`, is not SQLite, and has no `read` configuration |
| `server.port` | int | The `port` of the connection | `server.address` is present and the port is numeric |

The connection attributes are computed once per connection name and process.

### `db.system.name`

| Laravel driver | `db.system.name` |
|---|---|
| `mysql` | `mysql` |
| `mariadb` | `mariadb` |
| `pgsql` | `postgresql` |
| `sqlite` | `sqlite` |
| `sqlsrv` | `microsoft.sql_server` |
| Any other | `other_sql` |

### `db.operation.name`

The first word of the query, upper-cased, after leading spaces and `/* ... */` comments. It is set only for these keywords:

`ALTER`, `CALL`, `CREATE`, `DELETE`, `DROP`, `EXEC`, `EXPLAIN`, `INSERT`, `MERGE`, `PRAGMA`, `REPLACE`, `SELECT`, `SET`, `SHOW`, `TRUNCATE`, `UPDATE`, `UPSERT`

A query that starts with another word, such as `WITH`, has no `db.operation.name`.

## Recorded when

| Event | Effect |
|---|---|
| `Illuminate\Database\Events\QueryExecuted` | Records one value |

## Not recorded

- Failed queries. Laravel dispatches no `QueryExecuted` event for them.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `db.client.operation.duration` receives the `QueryExecuted` event, with `$event->sql`, `$event->time`, `$event->connection` and `$event->connectionName`.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.db` | `METRICS_DB` | `true` |
