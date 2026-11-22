<?php

declare(strict_types=1);

return [

    /*
    | Turn all metric recording and export on or off.
    */
    'enabled' => env('METRICS_ENABLED', true),

    /*
    | Where each request merges its metrics. PHP-FPM keeps no state between
    | requests, so the store holds the cumulative values for all workers.
    | The Redis store uses a connection from config/database.php.
    */
    'store' => [
        'driver' => env('METRICS_STORE', 'redis'),
        'connection' => env('METRICS_REDIS_CONNECTION', 'default'),
        'prefix' => env('METRICS_PREFIX', 'metrics:'),
    ],

    /*
    | The maximum number of attribute sets per instrument. Extra series go
    | to one overflow series, as the OpenTelemetry specification defines.
    */
    'cardinality_limit' => 2000,

];
