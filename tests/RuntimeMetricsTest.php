<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\ExportTimeMetrics;
use Nwidart\Metrics\Instrumentation\RuntimeMetrics;
use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;

final class RuntimeMetricsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('metrics.prometheus.enabled', true);
    }

    #[Test]
    public function the_scrape_reads_the_runtime_of_the_answering_process(): void
    {
        $output = (string) $this->get('/metrics')->assertOk()->getContent();

        $this->assertStringContainsString('laravel_info{laravel_version="'.$this->app->version().'",otel_scope_name="nwidart/laravel-metrics",php_sapi="cli",process_runtime_name="php",process_runtime_version="'.PHP_VERSION.'"} 1', $output);
        $this->assertMatchesRegularExpression('/^system_filesystem_usage_bytes\{otel_scope_name="nwidart\/laravel-metrics",system_filesystem_state="free"\} \d+$/m', $output);
        $this->assertMatchesRegularExpression('/^system_filesystem_usage_bytes\{otel_scope_name="nwidart\/laravel-metrics",system_filesystem_state="used"\} \d+$/m', $output);
        $this->assertStringContainsString('# TYPE system_cpu_load_average_1m gauge', $output);
        // Nothing read on export reaches the store.
        $this->app->terminate();
        $this->assertSame([], array_values(array_filter($this->collect(), fn ($metric) => $metric->name === 'laravel.info')));
    }

    #[Test]
    public function a_callback_runs_on_export_only_and_one_that_throws_is_skipped(): void
    {
        $calls = 0;
        $exportTime = $this->app->make(ExportTimeMetrics::class);
        $exportTime->register(function () {
            throw new RuntimeException('broken callback');
        });
        $exportTime->register(function (MeterProviderInterface $provider) use (&$calls): void {
            $calls++;
            $provider->getMeter('app')->createObservableGauge('queue.size', '{job}', null, [], fn (ObserverInterface $observer) => $observer->observe(4, ['queue' => 'default']));
        });

        $this->get('/');
        $this->app->terminate();
        $this->assertSame(0, $calls);

        $output = (string) $this->get('/metrics')->assertOk()->getContent();
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('queue_size{otel_scope_name="app",queue="default"} 4', $output);
    }

    #[Test]
    public function opcache_and_php_fpm_statuses_become_metrics(): void
    {
        $exportTime = new ExportTimeMetrics;
        $runtime = new RuntimeMetrics($this->app);
        $exportTime->register(function (MeterProviderInterface $provider) use ($runtime): void {
            $meter = $provider->getMeter('nwidart/laravel-metrics');
            (new ReflectionMethod($runtime, 'opcache'))->invoke($runtime, $meter, [
                'memory_usage' => ['used_memory' => 100, 'free_memory' => 200, 'wasted_memory' => 3],
                'opcache_statistics' => ['num_cached_scripts' => 42, 'hits' => 1000, 'misses' => 7],
            ]);
            (new ReflectionMethod($runtime, 'fpm'))->invoke($runtime, $meter, [
                'active-processes' => 2, 'idle-processes' => 3, 'listen-queue' => 1,
                'accepted-conn' => 500, 'max-children-reached' => 4, 'slow-requests' => 9,
            ]);
        });

        $output = (new PrometheusTextFormat)->render($exportTime->collect(ResourceInfoFactory::emptyResource()));

        foreach ([
            'php_opcache_memory_usage_bytes{otel_scope_name="nwidart/laravel-metrics",php_opcache_memory_state="used"} 100',
            'php_opcache_memory_usage_bytes{otel_scope_name="nwidart/laravel-metrics",php_opcache_memory_state="wasted"} 3',
            'php_opcache_scripts{otel_scope_name="nwidart/laravel-metrics"} 42',
            'php_opcache_lookups_total{otel_scope_name="nwidart/laravel-metrics",php_opcache_lookup_result="miss"} 7',
            'php_fpm_processes{otel_scope_name="nwidart/laravel-metrics",php_fpm_process_state="idle"} 3',
            'php_fpm_listen_queue{otel_scope_name="nwidart/laravel-metrics"} 1',
            'php_fpm_connections_accepted_total{otel_scope_name="nwidart/laravel-metrics"} 500',
            'php_fpm_max_children_reached_total{otel_scope_name="nwidart/laravel-metrics"} 4',
            'php_fpm_slow_requests_total{otel_scope_name="nwidart/laravel-metrics"} 9',
        ] as $sample) {
            $this->assertStringContainsString($sample, $output);
        }
    }

    #[Test]
    #[DefineEnvironment('disableRuntime')]
    public function it_can_be_turned_off(): void
    {
        $this->assertStringNotContainsString('laravel_info', (string) $this->get('/metrics')->getContent());
    }

    protected function disableRuntime(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.runtime', false);
    }
}
