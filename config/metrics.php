<?php

declare(strict_types=1);

return [

    /*
    | Turn all metric recording and export on or off. When off, the meter
    | provider records nothing and nothing touches Redis.
    */
    'enabled' => env('METRICS_ENABLED', true),

    /*
    | Where each request merges its metrics. PHP-FPM keeps no state between
    | requests, so the store holds the cumulative values for all workers.
    |
    | "redis"  for production. Uses a Redis connection from
    |          config/database.php; the connection's own key prefix is kept.
    |          The braces in the prefix are a Redis Cluster hash tag that
    |          keeps every store key in one slot.
    | "cache"  for development without Redis. One entry in a Laravel cache
    |          store (null is the default one), written under a lock, so
    |          writers wait for each other. `cache:clear` removes it.
    */
    'store' => [
        'driver' => env('METRICS_STORE', 'redis'),
        'connection' => env('METRICS_REDIS_CONNECTION', 'default'),
        'prefix' => env('METRICS_PREFIX', '{metrics}:'),
        'cache_store' => env('METRICS_CACHE_STORE'),
    ],

    /*
    | The maximum number of attribute sets per instrument. Extra series go
    | to one overflow series, as the OpenTelemetry specification defines.
    */
    'cardinality_limit' => 2000,

    /*
    | Make this package's meter provider the OpenTelemetry global one, so
    | that libraries which record through the OpenTelemetry API
    | (Globals::meterProvider()) feed the store too.
    */
    'register_global' => env('METRICS_REGISTER_GLOBAL', true),

    /*
    | Serve the store in the Prometheus text format, at GET /{path}. Off by
    | default: metrics can tell more about the application than it should
    | share. Protect the route with middleware or with the network.
    | The labels are added to every sample, e.g. ['service' => 'shop'].
    */
    'prometheus' => [
        'enabled' => env('METRICS_PROMETHEUS', false),
        'path' => env('METRICS_PROMETHEUS_PATH', 'metrics'),
        'middleware' => [],
        'labels' => [],
    ],

    /*
    | `php artisan metrics:push` sends the store to an OTLP/HTTP endpoint,
    | such as an OpenTelemetry Collector. Schedule it, for example every
    | minute. "/v1/metrics" is appended to the endpoint. The protocol is
    | "http/protobuf" or "http/json". The resource attributes describe the
    | whole application: the store aggregates every process and host.
    */
    'push' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
        'headers' => [],
        'timeout' => 10,
        'resource' => [
            'service.name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel')),
            'deployment.environment.name' => env('APP_ENV'),
        ],
    ],

    /*
    | Built-in instrumentation, following the OpenTelemetry semantic
    | conventions where they define the metric.
    */
    'instrumentation' => [
        // http.server.request.duration
        'http' => env('METRICS_HTTP', true),
        // db.client.operation.duration, for every successful query
        'db' => env('METRICS_DB', true),
        // messaging.process.duration and messaging.client.sent.messages
        'queue' => env('METRICS_QUEUE', true),
        // laravel.cache.operations: reads (hit, miss), writes and deletes, per store
        'cache' => env('METRICS_CACHE', true),
        // laravel.scheduler.task.duration, per scheduled task run
        'scheduler' => env('METRICS_SCHEDULER', true),
        // laravel.exceptions, per reported exception class
        'exceptions' => env('METRICS_EXCEPTIONS', true),
        // Read on export only (scrape, metrics:push), never stored: laravel.info,
        // OPcache, PHP-FPM, load average and disk usage of the exporting server
        'runtime' => env('METRICS_RUNTIME', true),
    ],

];
