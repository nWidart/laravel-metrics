<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use InvalidArgumentException;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use PHPUnit\Framework\Attributes\Test;

final class PushCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('metrics.push.resource', ['service.name' => 'shop', 'deployment.environment.name' => '']);
        // RuntimeMetricsTest covers the metrics read on export.
        $app['config']->set('metrics.instrumentation.runtime', false);
    }

    #[Test]
    public function it_sends_the_cumulative_values_with_the_configured_resource(): void
    {
        $exporter = new InMemoryExporter;
        $this->app->instance(MetricExporterInterface::class, $exporter);
        $counter = $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders');
        $counter->add(2);
        $this->app->terminate();
        $counter->add(3);
        $this->app->terminate();

        $this->artisan('metrics:push')->expectsOutputToContain('Sent 1 metrics.')->assertSuccessful();

        $metrics = $exporter->collect();
        $this->assertCount(1, $metrics);
        $this->assertInstanceOf(Metric::class, $metrics[0]);
        $this->assertInstanceOf(Sum::class, $metrics[0]->data);
        $this->assertSame(5, $metrics[0]->data->dataPoints[0]->value);
        // An empty attribute is left out.
        $this->assertSame(['service.name' => 'shop'], $metrics[0]->resource->getAttributes()->toArray());
    }

    #[Test]
    public function an_empty_store_sends_nothing(): void
    {
        $exporter = new InMemoryExporter;
        $this->app->instance(MetricExporterInterface::class, $exporter);

        $this->artisan('metrics:push')->expectsOutputToContain('The store holds no metrics.')->assertSuccessful();
        $this->assertSame([], $exporter->collect());
    }

    #[Test]
    public function a_refused_push_fails(): void
    {
        $this->app->instance(MetricExporterInterface::class, new class implements MetricExporterInterface
        {
            public function export(iterable $batch): bool
            {
                return false;
            }

            public function shutdown(): bool
            {
                return true;
            }
        });
        $this->app->make(MeterProviderInterface::class)->getMeter('app')->createCounter('orders')->add(1);
        $this->app->terminate();

        $this->artisan('metrics:push')->expectsOutputToContain('did not accept')->assertFailed();
    }

    #[Test]
    public function the_exporter_is_built_from_the_configuration(): void
    {
        $this->app['config']->set('metrics.push.endpoint', 'http://collector:4318/');

        $this->assertInstanceOf(MetricExporter::class, $this->app->make(MetricExporterInterface::class));
    }

    #[Test]
    public function a_missing_endpoint_is_a_clear_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metrics.push.endpoint');

        $this->app->make(MetricExporterInterface::class);
    }
}
