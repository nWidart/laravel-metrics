<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;

/**
 * Reads laravel.db.migrations.pending when the store is exported (see ExportTimeMetrics): the
 * migrations that `php artisan migrate` would run, from the same paths. Above 0 after a deploy,
 * the code expects a schema that the database does not have yet. A database that cannot be
 * reached leaves the metric out.
 */
final class MigrationMetrics
{
    public function __construct(private readonly Application $app) {}

    public function __invoke(MeterProviderInterface $meterProvider): void
    {
        try {
            $migrator = $this->app->make(Migrator::class);
            $files = $migrator->getMigrationFiles([...$migrator->paths(), $this->app->databasePath('migrations')]);
            // Without the migrations table, nothing has run.
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        } catch (Throwable) {
            return;
        }

        $pending = count(array_diff(array_keys($files), $ran));
        $meterProvider->getMeter('nwidart/laravel-metrics')->createObservableUpDownCounter('laravel.db.migrations.pending', '{migration}', 'Migrations that have not run yet.', [], fn (ObserverInterface $observer) => $observer->observe($pending));
    }
}
