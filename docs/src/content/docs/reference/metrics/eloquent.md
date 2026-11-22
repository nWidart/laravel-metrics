---
title: Eloquent models
description: The laravel.eloquent.models and laravel.eloquent.models.pruned counters, from Eloquent's model events.
---

`Nwidart\Metrics\Instrumentation\EloquentMetrics` counts Eloquent models from Eloquent's own events, per model class.

## Instruments

| Property | `laravel.eloquent.models` | `laravel.eloquent.models.pruned` |
|---|---|---|
| Kind | Counter | Counter |
| Unit | `{model}` | `{model}` |
| Buckets | None | None |
| Description | `Number of Eloquent models, by event.` | `Number of Eloquent models removed by model:prune.` |
| Meter | `nwidart/laravel-metrics` | `nwidart/laravel-metrics` |
| Prometheus name | `laravel_eloquent_models_total` | `laravel_eloquent_models_pruned_total` |

## Attributes

| Metric | Attribute | Type | Values |
|---|---|---|---|
| `laravel.eloquent.models` | `laravel.eloquent.event` | string | `retrieved`, `created`, `updated`, `deleted`, `restored` |
| | `laravel.eloquent.model` | string | The model class, such as `App\Models\Order` |
| `laravel.eloquent.models.pruned` | `laravel.eloquent.model` | string | The model class |

## Recorded when

| Event | Effect |
|---|---|
| `eloquent.retrieved: {class}` | Counts one model for each model a query returns |
| `eloquent.created`, `eloquent.updated`, `eloquent.deleted`, `eloquent.restored: {class}` | Counts one model |
| `Illuminate\Database\Events\ModelsPruned` | Adds the models pruned since the last event of the run |

A query that returns 10,000 rows dispatches 10,000 `retrieved` events. The models are counted in memory, at about 1.5 µs each with Laravel's event dispatch, and added to the counter when the metrics are [flushed](/laravel-metrics/guides/flush-long-running-work/).

`model:prune` dispatches a running total after each chunk. Only its growth is added, so each model counts once.

`updated` comes only for a model that had changes. `deleted` also comes for a soft delete.

## Not recorded

- Mass updates and deletes, such as `Order::where(...)->update()`. They are query builder calls, which dispatch no model event.
- Models saved without events (`saveQuietly()`, `Model::withoutEvents()`).

## Attribute contributor context

`laravel.eloquent.models` takes no contributed attributes: its models are added up long after their events. A [`Metrics::attributes()`](/laravel-metrics/reference/facade/#attributes) callback for `laravel.eloquent.models.pruned` receives the `ModelsPruned` event.

## Switch

| Key | Variable | Default |
|---|---|---|
| `instrumentation.eloquent` | `METRICS_ELOQUENT` | `true` |
