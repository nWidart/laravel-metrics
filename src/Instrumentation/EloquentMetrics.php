<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\ModelsPruned;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records Eloquent models, from Eloquent's own events:
 *   laravel.eloquent.models          laravel.eloquent.event = retrieved, created, updated, deleted
 *                                    or restored, per laravel.eloquent.model (the model class)
 *   laravel.eloquent.models.pruned   models removed by model:prune, per laravel.eloquent.model
 * A query that returns many rows dispatches one "retrieved" event per model, so counting must cost
 * next to nothing: the models are counted in memory, and added to the counter only when the
 * metrics are flushed. That leaves no event to tag: laravel.eloquent.models takes no contributed
 * attributes.
 *
 * Eloquent dispatches no event for a mass update or delete (a query builder call), and none for a
 * model saved without events (saveQuietly, withoutEvents). "updated" comes only for a model that
 * had changes, and "deleted" also for a soft delete.
 */
final class EloquentMetrics
{
    /** The Eloquent events that are counted. */
    public const EVENTS = ['retrieved', 'created', 'updated', 'deleted', 'restored'];

    /** @var array<string, array<string, int>> event => model class => count */
    private array $pending = [];

    /** @var array<string, int> model class => the running total of its last ModelsPruned event */
    private array $prunedTotals = [];

    private ?MeterProviderInterface $provider = null;

    private CounterInterface $models;

    private CounterInterface $pruned;

    public function __construct(private readonly Application $app, private readonly AttributeContributors $contributors) {}

    /** Counts one "eloquent.{event}: {model class}" event. */
    public function count(string $eventName): void
    {
        $separator = strpos($eventName, ': ');
        if ($separator !== false) {
            $event = substr($eventName, 9, $separator - 9);
            $model = substr($eventName, $separator + 2);
            $this->pending[$event][$model] = ($this->pending[$event][$model] ?? 0) + 1;
        }
    }

    /**
     * Pruning dispatches a running total after each chunk, which only goes up within a run. A
     * total that does not exceed the last one starts another run.
     */
    public function pruned(ModelsPruned $event): void
    {
        $last = $this->prunedTotals[$event->model] ?? 0;
        $this->prunedTotals[$event->model] = $event->count;
        $count = $event->count > $last ? $event->count - $last : $event->count;

        $this->instruments();
        $this->pruned->add($count, ['laravel.eloquent.model' => $event->model, ...$this->contributors->for('laravel.eloquent.models.pruned', $event)]);
    }

    /** Adds the models counted since the last call to the counter. Metrics::flush() calls it. */
    public function record(): void
    {
        if ($this->pending === []) {
            return;
        }

        $this->instruments();
        foreach ($this->pending as $event => $models) {
            foreach ($models as $model => $count) {
                $this->models->add($count, ['laravel.eloquent.event' => $event, 'laravel.eloquent.model' => $model]);
            }
        }
        $this->pending = [];
    }

    /**
     * The listener keeps this instance for the whole process, to count without asking the
     * container, so the instruments follow the meter provider, which Metrics::fake() replaces.
     */
    private function instruments(): void
    {
        $provider = $this->app->make(MeterProviderInterface::class);
        if ($provider === $this->provider) {
            return;
        }

        $meter = $provider->getMeter('nwidart/laravel-metrics');
        $this->models = $meter->createCounter('laravel.eloquent.models', '{model}', 'Number of Eloquent models, by event.');
        $this->pruned = $meter->createCounter('laravel.eloquent.models.pruned', '{model}', 'Number of Eloquent models removed by model:prune.');
        $this->provider = $provider;
    }
}
