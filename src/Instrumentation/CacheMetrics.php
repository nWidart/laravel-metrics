<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

/**
 * Records laravel.cache.operations, a counter of cache operations, from Laravel's cache events.
 *
 * The OpenTelemetry semantic conventions define no cache metrics, so the metric and its
 * attributes are in the laravel.* namespace:
 *   laravel.cache.store       the store name from config/cache.php (left out when Laravel has none)
 *   laravel.cache.operation   read, write or delete
 *   laravel.cache.result      hit or miss for a read; success or failure for a write or a delete
 *
 * The cache key is never an attribute: it would create one series per key. There is no duration:
 * Laravel 12.0 has start events (RetrievingKey, WritingKey), but pairing them with the end event
 * needs state per key, and many() dispatches one start event for several keys. The listener runs
 * on every cache call: it is a singleton, and each event costs one small array and one add().
 */
final class CacheMetrics
{
    private readonly CounterInterface $operations;

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors)
    {
        $this->operations = $meterProvider->getMeter('nwidart/laravel-metrics')->createCounter(
            'laravel.cache.operations',
            '{operation}',
            'Number of cache operations.',
        );
    }

    public function hit(CacheHit $event): void
    {
        $this->record($event, 'read', 'hit');
    }

    public function missed(CacheMissed $event): void
    {
        $this->record($event, 'read', 'miss');
    }

    public function written(KeyWritten $event): void
    {
        $this->record($event, 'write', 'success');
    }

    public function writeFailed(KeyWriteFailed $event): void
    {
        $this->record($event, 'write', 'failure');
    }

    public function forgotten(KeyForgotten $event): void
    {
        $this->record($event, 'delete', 'success');
    }

    public function forgetFailed(KeyForgetFailed $event): void
    {
        $this->record($event, 'delete', 'failure');
    }

    private function record(CacheEvent $event, string $operation, string $result): void
    {
        $attributes = ['laravel.cache.operation' => $operation, 'laravel.cache.result' => $result];
        if (is_string($event->storeName)) {
            $attributes['laravel.cache.store'] = $event->storeName;
        }

        $this->operations->add(1, [...$attributes, ...$this->contributors->for('laravel.cache.operations', $event)]);
    }
}
