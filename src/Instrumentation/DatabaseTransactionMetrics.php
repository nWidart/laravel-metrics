<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use WeakMap;

/**
 * Records laravel.db.transaction.duration, one value per outer transaction, from its beginning to
 * its commit or its rollback. laravel.db.transaction.result is committed or rolled_back, so the
 * count of the histogram is the number of transactions and the share of rollbacks. The other
 * attributes are those of db.client.operation.duration that describe the connection.
 *
 * A nested transaction (a savepoint) is part of its outer transaction and is not recorded on its
 * own. Laravel dispatches no event when a commit fails or when the connection is lost: such a
 * transaction is not recorded. Starts are kept per connection object, as a stack: an afterCommit
 * callback that runs a transaction does so before the commit event of the transaction that ran it.
 */
final class DatabaseTransactionMetrics
{
    /** The bucket boundaries of db.client.operation.duration, in seconds. */
    private const BUCKETS = [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10];

    private readonly HistogramInterface $duration;

    /** @var WeakMap<Connection, list<int|float>> connection => starts of its outer transactions, from hrtime() */
    private WeakMap $started;

    /**
     * Per connection object, not per name: a connection purged and built again, such as a
     * tenant's, may point to another database. Under Octane it outlives the request.
     *
     * @var WeakMap<Connection, array<non-empty-string, string|int>>
     */
    private WeakMap $connections;

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors)
    {
        $this->duration = $meterProvider->getMeter('nwidart/laravel-metrics')->createHistogram(
            'laravel.db.transaction.duration',
            's',
            'Duration of database transactions, from their beginning to their commit or rollback.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
        $this->started = new WeakMap;
        $this->connections = new WeakMap;
    }

    public function began(TransactionBeginning $event): void
    {
        // The event comes after the level went up: 1 is an outer transaction.
        if ($event->connection->transactionLevel() === 1) {
            $starts = $this->started[$event->connection] ?? [];
            $starts[] = hrtime(true);
            $this->started[$event->connection] = $starts;
        }
    }

    public function committed(TransactionCommitted $event): void
    {
        $this->ended($event, 'committed');
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        $this->ended($event, 'rolled_back');
    }

    private function ended(ConnectionEvent $event, string $result): void
    {
        // The event comes after the level went down: 0 ends the outer transaction.
        $connection = $event->connection;
        $starts = $this->started[$connection] ?? [];
        if ($connection->transactionLevel() !== 0 || $starts === []) {
            return;
        }
        $elapsed = (hrtime(true) - array_pop($starts)) / 1e9;
        $this->started[$connection] = $starts;

        $attributes = $this->connections[$connection] ??= ConnectionAttributes::of($connection);
        $attributes['laravel.db.transaction.result'] = $result;

        $this->duration->record(max(0.0, $elapsed), [...$attributes, ...$this->contributors->for('laravel.db.transaction.duration', $event)]);
    }
}
