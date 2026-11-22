<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Contracts\Foundation\Application;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * Metrics read when the store is exported (see ExportTimeMetrics). They describe the process and
 * the server that export them, not the whole application:
 *   laravel.info                    1, with laravel.version, process.runtime.name and .version, php.sapi
 *   php.opcache.memory.usage        By, php.opcache.memory.state = used, free or wasted
 *   php.opcache.scripts             cached scripts
 *   php.opcache.lookups             counter, php.opcache.lookup.result = hit or miss
 *   php.fpm.processes               php.fpm.process.state = active or idle
 *   php.fpm.listen_queue            requests waiting for a free worker
 *   php.fpm.connections.accepted    counter
 *   php.fpm.max_children_reached    counter
 *   php.fpm.slow_requests           counter
 *   system.cpu.load_average.1m      the name of the Collector's host metrics receiver
 *   system.filesystem.usage         By, system.filesystem.state = used or free, of the disk that holds
 *                                   the application, as the semantic conventions define it
 * The semantic conventions define no PHP runtime metrics, so the PHP ones are in a php namespace,
 * like jvm.* and v8js.*. OPcache metrics exist only when OPcache runs, which it does not on the
 * command line by default; PHP-FPM metrics only under PHP-FPM.
 */
final class RuntimeMetrics
{
    public function __construct(private readonly Application $app) {}

    public function __invoke(MeterProviderInterface $meterProvider): void
    {
        $meter = $meterProvider->getMeter('nwidart/laravel-metrics');

        $meter->createObservableGauge('laravel.info', null, 'Laravel and PHP versions of the process that exports the metrics.', [], fn (ObserverInterface $observer) => $observer->observe(1, [
            'laravel.version' => $this->app->version(),
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'php.sapi' => PHP_SAPI,
        ]));

        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        // The status is false when OPcache is off, as it usually is on the command line.
        if (is_array($status)) {
            $this->opcache($meter, $status);
        }

        $fpm = function_exists('fpm_get_status') ? fpm_get_status() : false;
        if (is_array($fpm)) {
            $this->fpm($meter, $fpm);
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (is_array($load)) {
            $meter->createObservableGauge('system.cpu.load_average.1m', '{thread}', 'Average number of threads ready or running over the last minute.', [], fn (ObserverInterface $observer) => $observer->observe($load[0]));
        }

        // False means the disk cannot be read. Zero free space is a full disk, and is reported.
        $free = @disk_free_space($this->app->basePath());
        $total = @disk_total_space($this->app->basePath());
        if ($free !== false && $total !== false) {
            $meter->createObservableUpDownCounter('system.filesystem.usage', 'By', 'Space usage of the disk that holds the application.', [], function (ObserverInterface $observer) use ($free, $total): void {
                $observer->observe((int) $free, ['system.filesystem.state' => 'free']);
                $observer->observe((int) ($total - $free), ['system.filesystem.state' => 'used']);
            });
        }
    }

    /**
     * @param  array<mixed>  $status
     */
    private function opcache(MeterInterface $meter, array $status): void
    {
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];

        $meter->createObservableUpDownCounter('php.opcache.memory.usage', 'By', 'OPcache shared memory, by state.', [], function (ObserverInterface $observer) use ($memory): void {
            foreach (['used' => 'used_memory', 'free' => 'free_memory', 'wasted' => 'wasted_memory'] as $state => $key) {
                if (is_int($memory[$key] ?? null)) {
                    $observer->observe($memory[$key], ['php.opcache.memory.state' => $state]);
                }
            }
        });
        if (is_int($statistics['num_cached_scripts'] ?? null)) {
            $meter->createObservableUpDownCounter('php.opcache.scripts', '{script}', 'Scripts held in OPcache.', [], fn (ObserverInterface $observer) => $observer->observe($statistics['num_cached_scripts']));
        }
        $meter->createObservableCounter('php.opcache.lookups', '{lookup}', 'OPcache lookups, by result.', [], function (ObserverInterface $observer) use ($statistics): void {
            foreach (['hit' => 'hits', 'miss' => 'misses'] as $result => $key) {
                if (is_int($statistics[$key] ?? null)) {
                    $observer->observe($statistics[$key], ['php.opcache.lookup.result' => $result]);
                }
            }
        });
    }

    /**
     * @param  array<mixed>  $status
     */
    private function fpm(MeterInterface $meter, array $status): void
    {
        $meter->createObservableUpDownCounter('php.fpm.processes', '{process}', 'PHP-FPM worker processes of the pool, by state.', [], function (ObserverInterface $observer) use ($status): void {
            foreach (['active' => 'active-processes', 'idle' => 'idle-processes'] as $state => $key) {
                if (is_int($status[$key] ?? null)) {
                    $observer->observe($status[$key], ['php.fpm.process.state' => $state]);
                }
            }
        });

        $gauges = ['php.fpm.listen_queue' => ['listen-queue', '{request}', 'Requests waiting for a free PHP-FPM worker.']];
        $counters = [
            'php.fpm.connections.accepted' => ['accepted-conn', '{connection}', 'Requests accepted by the PHP-FPM pool.'],
            'php.fpm.max_children_reached' => ['max-children-reached', '{event}', 'Times the PHP-FPM pool reached its process limit.'],
            'php.fpm.slow_requests' => ['slow-requests', '{request}', 'Requests slower than the PHP-FPM slow log timeout.'],
        ];
        foreach ($gauges as $name => [$key, $unit, $description]) {
            if (is_int($status[$key] ?? null)) {
                $value = $status[$key];
                $meter->createObservableUpDownCounter($name, $unit, $description, [], fn (ObserverInterface $observer) => $observer->observe($value));
            }
        }
        foreach ($counters as $name => [$key, $unit, $description]) {
            if (is_int($status[$key] ?? null)) {
                $value = $status[$key];
                $meter->createObservableCounter($name, $unit, $description, [], fn (ObserverInterface $observer) => $observer->observe($value));
            }
        }
    }
}
