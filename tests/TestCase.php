<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Nwidart\Metrics\MetricsServiceProvider;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Orchestra\Testbench\TestCase as Orchestra;
use Predis\Client;
use Redis;
use Throwable;

/**
 * Runs against a real Redis, database 15. Start it with `docker compose up -d redis`.
 * Uses phpredis when ext-redis is loaded, predis otherwise. Set REDIS_HOST and REDIS_PORT to use
 * another server.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MetricsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', extension_loaded('redis') ? 'phpredis' : 'predis');
        $app['config']->set('database.redis.default.host', getenv('REDIS_HOST') ?: '127.0.0.1');
        $app['config']->set('database.redis.default.port', getenv('REDIS_PORT') ?: '6379');
        $app['config']->set('database.redis.default.database', '15');
        $app['config']->set('metrics.store.prefix', '{laravel-test}:');
        // Tests report exceptions on purpose; keep them out of vendor/ (read-only in the bench container).
        $app['config']->set('logging.default', 'null');
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->store()->clear();
        } catch (Throwable $e) {
            self::markTestSkipped('Redis is not reachable ('.$e->getMessage().'). Run `docker compose up -d redis`.');
        }
    }

    protected function tearDown(): void
    {
        $this->store()->clear();

        parent::tearDown();
    }

    /**
     * Keys that match the pattern, read with a client that has no key prefix.
     *
     * @return list<string>
     */
    protected function rawKeys(string $pattern): array
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        if (extension_loaded('redis')) {
            $redis = new Redis;
            $redis->connect($host, $port);
            $redis->select(15);
            $keys = $redis->keys($pattern);
        } else {
            $keys = (new Client(['host' => $host, 'port' => $port, 'database' => 15]))->keys($pattern);
        }

        return array_values(array_filter($keys, is_string(...)));
    }

    protected function store(): MetricStore
    {
        return $this->app->make(MetricStore::class);
    }

    /**
     * @return list<Metric>
     */
    protected function collect(): array
    {
        return $this->store()->collect(ResourceInfoFactory::emptyResource());
    }

    /**
     * @return list<HistogramDataPoint>
     */
    protected function histogramPoints(string $name): array
    {
        foreach ($this->collect() as $metric) {
            if ($metric->name === $name) {
                self::assertInstanceOf(Histogram::class, $metric->data);
                $points = [];
                foreach ($metric->data->dataPoints as $point) {
                    self::assertInstanceOf(HistogramDataPoint::class, $point);
                    $points[] = $point;
                }

                return $points;
            }
        }

        return [];
    }
}
