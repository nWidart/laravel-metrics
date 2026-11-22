<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Database\Events\ConnectionEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records laravel.db.transaction.duration, one value per outer transaction, from its beginning to
 * its commit or its rollback. laravel.db.transaction.result is committed or rolled_back, so the
 * count of the histogram is the number of transactions and the share of rollbacks. The other
 * attributes are those of db.client.operation.duration that describe the connection.
 *
 * A nested transaction (a savepoint) is part of its outer transaction and is not recorded on its
 * own. Laravel dispatches no event when a commit fails or when the connection is lost: such a
 * transaction is not recorded, and the next one on the connection starts afresh.
 */
final class DatabaseTransactionMetrics
{
    /** The bucket boundaries of db.client.operation.duration, in seconds. */
    private const BUCKETS = [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10];

    private readonly HistogramInterface $duration;

    /** @var array<string, int|float> connection name => start of its outer transaction, from hrtime() */
    private array $started = [];

    /** @var array<string, array<non-empty-string, string|int>> connection name => attributes */
    private array $connections = [];

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors)
    {
        $this->duration = $meterProvider->getMeter('nwidart/laravel-metrics')->createHistogram(
            'laravel.db.transaction.duration',
            's',
            'Duration of database transactions, from their beginning to their commit or rollback.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
    }

    public function began(TransactionBeginning $event): void
    {
        // The event comes after the level went up: 1 is an outer transaction.
        if ($event->connection->transactionLevel() === 1) {
            $this->started[$event->connectionName] = hrtime(true);
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
        $name = $event->connectionName;
        if ($event->connection->transactionLevel() !== 0 || ! isset($this->started[$name])) {
            return;
        }
        $elapsed = (hrtime(true) - $this->started[$name]) / 1e9;
        unset($this->started[$name]);

        $attributes = $this->connections[$name] ??= ConnectionAttributes::of($event->connection);
        $attributes['laravel.db.transaction.result'] = $result;

        $this->duration->record(max(0.0, $elapsed), [...$attributes, ...$this->contributors->for('laravel.db.transaction.duration', $event)]);
    }
}
