<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;

/**
 * Metrics read when the store is exported (see ExportTimeMetrics), from the server of the default
 * database connection, with the attributes of db.client.operation.duration that describe it:
 *   laravel.db.server.connections       connections open on the server, from every client, as
 *                                       `php artisan db:monitor` counts them
 *   laravel.db.server.connections.max   the most connections the server accepts
 * Under PHP-FPM, each busy worker holds a connection: a server that reaches its maximum refuses
 * the next request's. MySQL, MariaDB and PostgreSQL have both metrics. SQL Server counts only
 * running sessions, and SQLite has none. A server that cannot be reached leaves both metrics out:
 * the database health check of the application says why.
 */
final class DatabaseServerMetrics
{
    /** Laravel driver name => the query that reads the server's connection limit. */
    private const MAX_CONNECTIONS = [
        'mysql' => 'select @@max_connections',
        'mariadb' => 'select @@max_connections',
        'pgsql' => 'show max_connections',
        'sqlsrv' => 'select @@max_connections',
    ];

    public function __construct(private readonly ConnectionResolverInterface $db) {}

    public function __invoke(MeterProviderInterface $meterProvider): void
    {
        try {
            $connection = $this->db->connection();
            if (! $connection instanceof Connection || ! isset(self::MAX_CONNECTIONS[$connection->getDriverName()])) {
                return;
            }
            $open = $connection->threadCount();
            $max = $connection->scalar(self::MAX_CONNECTIONS[$connection->getDriverName()]);
        } catch (Throwable) {
            return;
        }

        $attributes = ConnectionAttributes::of($connection);
        $meter = $meterProvider->getMeter('nwidart/laravel-metrics');
        if (is_numeric($open)) {
            $meter->createObservableUpDownCounter('laravel.db.server.connections', '{connection}', 'Connections open on the database server.', [], fn (ObserverInterface $observer) => $observer->observe((int) $open, $attributes));
        }
        if (is_numeric($max)) {
            $meter->createObservableUpDownCounter('laravel.db.server.connections.max', '{connection}', 'Most connections the database server accepts.', [], fn (ObserverInterface $observer) => $observer->observe((int) $max, $attributes));
        }
    }
}
