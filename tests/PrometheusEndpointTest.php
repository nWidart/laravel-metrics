<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Foundation\Application;
use Nwidart\Metrics\Redis\LaravelRedisClient;
use Nwidart\MetricsStore\MetricStore;
use Nwidart\MetricsStore\RedisStore;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class PrometheusEndpointTest extends TestCase
{
    #[Test]
    public function the_route_is_off_by_default(): void
    {
        $this->get('/metrics')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enablePrometheus')]
    public function it_serves_the_store_in_the_prometheus_text_format(): void
    {
        $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders.placed', '{order}', 'Orders placed.')->add(3, ['channel' => 'web']);
        $this->app->terminate();

        $response = $this->get('/metrics');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $this->assertStringContainsString(<<<'TEXT'
            # HELP orders_placed_total Orders placed.
            # TYPE orders_placed_total counter
            orders_placed_total{channel="web",otel_scope_name="app",service="shop"} 3

            TEXT, (string) $response->getContent());
    }

    #[Test]
    #[DefineEnvironment('enablePrometheus')]
    public function the_scrape_itself_is_recorded_after_it_answers(): void
    {
        $this->assertStringNotContainsString('http_server_request_duration_seconds', (string) $this->get('/metrics')->getContent());

        $this->assertStringContainsString('http_route="/metrics"', (string) $this->get('/metrics')->getContent());
    }

    #[Test]
    #[DefineEnvironment('enablePrometheusWithPathAndMiddleware')]
    public function the_path_and_the_middleware_are_configurable(): void
    {
        $this->get('/metrics')->assertNotFound();
        $this->getJson('/internal/prometheus')->assertUnauthorized();
    }

    #[Test]
    #[DefineEnvironment('enablePrometheusWithUnreachableStore')]
    public function a_store_that_cannot_be_read_answers_503(): void
    {
        $this->app->instance(MetricStore::class, new RedisStore(new LaravelRedisClient($this->app->make('redis'), 'unreachable')));

        $this->get('/metrics')->assertStatus(503);

        // The next resolution builds the reachable store again, for tearDown().
        $this->app->forgetInstance(MetricStore::class);
    }

    protected function enablePrometheus(Application $app): void
    {
        $app['config']->set('metrics.prometheus.enabled', true);
        $app['config']->set('metrics.prometheus.labels', ['service' => 'shop']);
    }

    protected function enablePrometheusWithPathAndMiddleware(Application $app): void
    {
        $app['config']->set('metrics.prometheus.enabled', true);
        $app['config']->set('metrics.prometheus.path', 'internal/prometheus');
        $app['config']->set('metrics.prometheus.middleware', [Authenticate::class]);
    }

    protected function enablePrometheusWithUnreachableStore(Application $app): void
    {
        $app['config']->set('metrics.prometheus.enabled', true);
        $app['config']->set('database.redis.unreachable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 15, 'timeout' => 0.2]);
    }
}
