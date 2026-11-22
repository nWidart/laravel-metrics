<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\MySqlConnection;
use Mockery;
use Nwidart\Metrics\ExportTimeMetrics;
use Nwidart\Metrics\Instrumentation\DatabaseServerMetrics;
use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class DatabaseServerMetricsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('metrics.prometheus.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    #[Test]
    public function the_scrape_reads_the_open_and_maximum_connections_of_the_server(): void
    {
        $output = $this->render(new DatabaseServerMetrics($this->resolver($this->mysql(['host' => 'db.internal', 'port' => '3306'], fn (string $query) => match ($query) {
            'select @@max_connections' => '151',
            default => 12,
        }))));

        $this->assertStringContainsString('laravel_db_server_connections{db_namespace="shop",db_system_name="mysql",otel_scope_name="nwidart/laravel-metrics",server_address="db.internal",server_port="3306"} 12', $output);
        $this->assertStringContainsString('laravel_db_server_connections_max{db_namespace="shop",db_system_name="mysql",otel_scope_name="nwidart/laravel-metrics",server_address="db.internal",server_port="3306"} 151', $output);
    }

    #[Test]
    public function a_server_that_cannot_be_reached_leaves_the_metrics_out(): void
    {
        $connection = $this->mysql([], fn () => throw new RuntimeException('Connection refused'));

        $this->assertStringNotContainsString('laravel_db_server_connections', $this->render(new DatabaseServerMetrics($this->resolver($connection))));
    }

    #[Test]
    public function sqlite_has_no_server_metrics(): void
    {
        $this->assertStringNotContainsString('laravel_db_server_connections', (string) $this->get('/metrics')->assertOk()->getContent());
    }

    #[Test]
    public function the_scrape_counts_the_migrations_that_have_not_run(): void
    {
        $this->app->make('migrator')->path(__DIR__.'/Fixtures/migrations');

        $this->assertStringContainsString("laravel_db_migrations_pending{otel_scope_name=\"nwidart/laravel-metrics\"} 2\n", (string) $this->get('/metrics')->assertOk()->getContent());

        $this->artisan('migrate')->assertSuccessful();

        $this->assertStringContainsString("laravel_db_migrations_pending{otel_scope_name=\"nwidart/laravel-metrics\"} 0\n", (string) $this->get('/metrics')->assertOk()->getContent());
    }

    #[Test]
    #[DefineEnvironment('disableDatabaseExports')]
    public function they_can_be_turned_off(): void
    {
        $this->app->make('migrator')->path(__DIR__.'/Fixtures/migrations');

        $this->assertStringNotContainsString('laravel_db_migrations_pending', (string) $this->get('/metrics')->assertOk()->getContent());
    }

    protected function disableDatabaseExports(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.db_server', false);
        $app['config']->set('metrics.instrumentation.migrations', false);
    }

    /**
     * A MySQL connection whose scalar queries (threadCount() is one) the callback answers.
     *
     * @param  array<string, string>  $config
     * @param  Closure(string): mixed  $scalar
     */
    private function mysql(array $config, Closure $scalar): MySqlConnection
    {
        return new class($scalar, $config) extends MySqlConnection
        {
            /** @param  array<string, string>  $config */
            public function __construct(private readonly Closure $answer, array $config)
            {
                parent::__construct(fn () => throw new RuntimeException('not connected'), 'shop', '', ['driver' => 'mysql', 'name' => 'mysql', ...$config]);
            }

            public function scalar($query, $bindings = [], $useReadPdo = true)
            {
                return ($this->answer)($query);
            }
        };
    }

    private function resolver(MySqlConnection $connection): ConnectionResolverInterface
    {
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        return $resolver;
    }

    private function render(DatabaseServerMetrics $metrics): string
    {
        $exportTime = new ExportTimeMetrics;
        $exportTime->register(fn (MeterProviderInterface $provider) => $metrics($provider));

        return (new PrometheusTextFormat)->render($exportTime->collect(ResourceInfoFactory::emptyResource()));
    }
}
