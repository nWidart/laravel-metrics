<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Instrumentation;

use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Throwable;

/**
 * Records laravel.exceptions, a counter of the exceptions the application reports, with the
 * exception.type attribute of the OpenTelemetry semantic conventions (the class name). The
 * conventions define no exception metric, so the name is in the laravel namespace.
 *
 * It is a reportable() callback of the exception handler, so it counts what reaches the log:
 * not the exceptions Laravel does not report (a 404, a validation failure), not an exception class
 * with its own report() method, and not one that an earlier reportable() callback stops.
 */
final class ExceptionMetrics
{
    private readonly CounterInterface $exceptions;

    public function __construct(MeterProviderInterface $meterProvider, private readonly AttributeContributors $contributors)
    {
        $this->exceptions = $meterProvider->getMeter('nwidart/laravel-metrics')->createCounter(
            'laravel.exceptions',
            '{exception}',
            'Number of exceptions reported by the application.',
        );
    }

    public function report(Throwable $e): void
    {
        $class = $e::class;
        // An anonymous class name holds a file path and a NUL byte.
        $anonymous = strpos($class, '@anonymous');

        $this->exceptions->add(1, [
            'exception.type' => $anonymous === false ? $class : substr($class, 0, $anonymous + 10),
            ...$this->contributors->for('laravel.exceptions', $e),
        ]);
    }
}
