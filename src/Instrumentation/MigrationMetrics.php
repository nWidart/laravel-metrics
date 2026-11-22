<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;

/**
 * Reads laravel.db.migrations.pending when the store is exported (see ExportTimeMetrics): the
 * migrations that `php artisan migrate` would run, from the same paths, without those it skips
 * (Migrator::withoutMigrations(), a shouldRun() that returns false). Above 0 after a deploy,
 * the code expects a schema that the database does not have yet. A database that cannot be
 * reached leaves the metric out.
 */
final class MigrationMetrics
{
    public function __construct(private readonly Application $app) {}

    public function __invoke(MeterProviderInterface $meterProvider): void
    {
        try {
            // Laravel 12.0 binds the migrator by name only.
            /** @var Migrator $migrator */
            $migrator = $this->app->make('migrator');
            $files = $migrator->getMigrationFiles([...$migrator->paths(), $this->app->databasePath('migrations')]);
            // Without the migrations table, nothing has run.
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            // What migrate runs, with Laravel's own filter: without the migrations that have run,
            // those of withoutMigrations(), and those whose shouldRun() returns false (Laravel 12.x).
            $pending = (fn (): int => count(array_filter(
                $this->pendingMigrations($files, $ran),
                fn (string $path): bool => ! (($migration = $this->resolvePath($path)) instanceof Migration
                    // @phpstan-ignore function.alreadyNarrowedType (Laravel 12.0 has no shouldRun())
                    && method_exists($migration, 'shouldRun') && ! $migration->shouldRun()),
            )))->call($migrator);
        } catch (Throwable) {
            return;
        }

        $meterProvider->getMeter('nwidart/laravel-metrics')->createObservableUpDownCounter('laravel.db.migrations.pending', '{migration}', 'Migrations that have not run yet.', [], fn (ObserverInterface $observer) => $observer->observe($pending));
    }
}
