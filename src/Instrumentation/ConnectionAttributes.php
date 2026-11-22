<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Database\Connection;

/**
 * The attributes that describe a database connection, as the OpenTelemetry database semantic
 * conventions define them: db.system.name, db.namespace, server.address and server.port.
 *
 * @internal
 */
final class ConnectionAttributes
{
    /** Laravel driver name => db.system.name. */
    private const SYSTEMS = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'pgsql' => 'postgresql',
        'sqlite' => 'sqlite',
        'sqlsrv' => 'microsoft.sql_server',
    ];

    /**
     * @return array<non-empty-string, string|int>
     */
    public static function of(Connection $connection): array
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
