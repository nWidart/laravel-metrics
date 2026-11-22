---
title: Database transactions
description: The laravel.db.transaction.duration histogram, one value per outer transaction, with its result.
---

`Nwidart\Metrics\Instrumentation\DatabaseTransactionMetrics` records one value per outer transaction, from its beginning to its commit or rollback. The OpenTelemetry semantic conventions define no transaction metric, so the metric uses the `laravel.` namespace.

## Instrument

| Property | Value |
|---|---|
| Name | `laravel.db.transaction.duration` |
| Kind | Histogram |
| Unit | `s` |
| Buckets | `0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10` |
| Description | `Duration of database transactions, from their beginning to their commit or rollback.` |
| Meter | `nwidart/laravel-metrics` |
| Prometheus name | `laravel_db_transaction_duration_seconds` |

The count of the histogram is the number of transactions. The share of `rolled_back` is the rollback rate.

## Attributes

| Attribute | Type | Values | Present when |
|---|---|---|---|
| `laravel.db.transaction.result` | string | `committed`, `rolled_back` | Always |
| `db.system.name` | string | As on [`db.client.operation.duration`](/laravel-metrics/reference/metrics/database/#dbsystemname) | Always |
| `db.namespace` | string | The database name of the connection | The database name is not empty |
| `server.address` | string | The `host` of the connection | The connection has a `host`, is not SQLite, and has no `read` configuration |
| `server.port` | int | The `port` of the connection | `server.address` is present and the port is numeric |

## Recorded when

| Event | Effect |
|---|---|
| `Illuminate\Database\Events\TransactionBeginning` | Starts the timer when the transaction level becomes 1 |
| `Illuminate\Database\Events\TransactionCommitted` | Records `committed` when the level returns to 0 |
| `Illuminate\Database\Events\TransactionRolledBack` | Records `rolled_back` when the level returns to 0 |

A nested transaction (a savepoint) is part of its outer transaction and is not recorded on its own. Starts are kept per connection object, as a stack. An `afterCommit` callback that runs a transaction is recorded separately, and so is each connection object that shares a name, such as `mysql` and `mysql::write`.

## Not recorded

- A transaction whose commit fails, or whose connection is lost. Laravel dispatches no event for either.

## Attribute contributor context

A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `laravel.db.transaction.duration` receives the `TransactionCommitted` or `TransactionRolledBack` event, with `$event->connection` and `$event->connectionName`.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.db` | `METRICS_DB` | `true` |
