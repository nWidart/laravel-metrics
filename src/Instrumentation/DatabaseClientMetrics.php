<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records db.client.operation.duration for every successful query, as the OpenTelemetry semantic
 * conventions define it. Laravel dispatches no event for a failed query, so failures are not
 * recorded. The listener runs on every query: it is a singleton, and it computes the attributes
 * of each connection once.
 */
final class DatabaseClientMetrics
{
    /** Bucket boundaries the semantic conventions advise, in seconds. */
    private const BUCKETS = [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10];

    /** Laravel driver name => db.system.name. */
    private const SYSTEMS = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'pgsql' => 'postgresql',
        'sqlite' => 'sqlite',
        'sqlsrv' => 'microsoft.sql_server',
    ];

    /**
     * The first keyword of a query becomes db.operation.name only when it is one of these, so that
     * unusual statements cannot create series at will.
     */
    private const OPERATIONS = [
        'ALTER', 'CALL', 'CREATE', 'DELETE', 'DROP', 'EXEC', 'EXPLAIN', 'INSERT', 'MERGE',
        'PRAGMA', 'REPLACE', 'SELECT', 'SET', 'SHOW', 'TRUNCATE', 'UPDATE', 'UPSERT',
    ];

    private readonly HistogramInterface $duration;

    /** @var array<string, array<non-empty-string, string|int>> connection name => attributes */
    private array $connections = [];

    public function __construct(MeterProviderInterface $meterProvider)
    {
        $this->duration = $meterProvider->getMeter('nwidart/laravel-metrics')->createHistogram(
            'db.client.operation.duration',
            's',
            'Duration of database client operations.',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );
    }

    public function handle(QueryExecuted $event): void
    {
        $attributes = $this->connections[$event->connectionName] ??= self::connectionAttributes($event->connection);

        if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*([a-z]+)/is', $event->sql, $match) === 1) {
            $operation = strtoupper($match[1]);
            if (in_array($operation, self::OPERATIONS, true)) {
                $attributes['db.operation.name'] = $operation;
            }
        }

        $this->duration->record(max(0.0, (float) $event->time / 1000), $attributes);
    }

    /**
     * @return array<non-empty-string, string|int>
     */
    private static function connectionAttributes(Connection $connection): array
    {
        $driver = $connection->getDriverName();
        $attributes = ['db.system.name' => self::SYSTEMS[$driver] ?? 'other_sql'];

        $database = $connection->getDatabaseName();
        if ($database !== '') {
            $attributes['db.namespace'] = $database;
        }

        // A connection with separate read and write hosts has no single server address.
        $host = $connection->getConfig('host');
        if (is_string($host) && $host !== '' && $driver !== 'sqlite' && $connection->getConfig('read') === null) {
            $attributes['server.address'] = $host;
            $port = $connection->getConfig('port');
            if (is_numeric($port)) {
                $attributes['server.port'] = (int) $port;
            }
        }

        return $attributes;
    }
}
