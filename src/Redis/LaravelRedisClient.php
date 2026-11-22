<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Redis;

use Illuminate\Contracts\Redis\Factory;
use Nwidart\MetricsStore\Redis\PhpRedisClient;
use Nwidart\MetricsStore\Redis\PredisClient;
use Nwidart\MetricsStore\Redis\RedisClient;
use Predis\ClientInterface;
use Redis;
use RuntimeException;

/**
 * Runs store scripts on a Laravel Redis connection. The connection is resolved on the first
 * script call, not when the store is built, so an unreachable Redis fails the flush (which
 * the exporter catches) and never the request.
 */
final class LaravelRedisClient implements RedisClient
{
    private ?RedisClient $client = null;

    public function __construct(
        private readonly Factory $redis,
        private readonly ?string $connection,
    ) {}

    public function evalScript(string $script, array $keys, array $args): mixed
    {
        return ($this->client ??= $this->resolve())->evalScript($script, $keys, $args);
    }

    private function resolve(): RedisClient
    {
        $client = $this->redis->connection($this->connection)->client();

        return match (true) {
            $client instanceof Redis => new PhpRedisClient($client),
            $client instanceof ClientInterface => new PredisClient($client),
            default => throw new RuntimeException(sprintf(
                'The metrics store needs a phpredis or predis connection, got %s. Redis Cluster through phpredis is not supported.',
                get_debug_type($client),
            )),
        };
    }
}
