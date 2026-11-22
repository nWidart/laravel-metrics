<?php

declare(strict_types=1);

namespace Nwidart\Metrics;

use Closure;
use Throwable;

/**
 * Callbacks that add attributes to a built-in metric, such as a tenant or a module on
 * http.server.request.duration. A callback receives what the measurement is about (the event,
 * or the exception for laravel.exceptions) and returns attributes; they win over the built-in
 * ones of the same name. Keep the number of distinct values small: each one is a new series.
 *
 * A callback is code from elsewhere that runs inside the work it describes, so nothing it does
 * may fail that work: one that throws is reported once and skipped, and a value that is not a
 * string, a number or a boolean is dropped. While a callback runs, the measurements it causes
 * (a query, a cache read) get no contributed attributes, so that a callback never calls itself.
 */
final class AttributeContributors
{
    /** @var array<string, list<Closure(object): mixed>> metric name => callbacks */
    private array $callbacks = [];

    private bool $running = false;

    private bool $failureReported = false;

    /**
     * @template TContext of object
     *
     * @param  Closure(TContext): array<string, string|int|float|bool>  $callback
     */
    public function add(string $metric, Closure $callback): void
    {
        /** @var Closure(object): mixed $callback */
        $this->callbacks[$metric][] = $callback;
    }

    /**
     * The attributes the callbacks of one metric contribute for one measurement.
     *
     * @return array<non-empty-string, string|int|float|bool>
     */
    public function for(string $metric, object $context): array
    {
        if (! isset($this->callbacks[$metric]) || $this->running) {
            return [];
        }

        $attributes = [];
        $this->running = true;
        try {
            foreach ($this->callbacks[$metric] as $callback) {
                try {
                    $contributed = $callback($context);
                } catch (Throwable $e) {
                    $this->reportOnce($e);

                    continue;
                }
                foreach (is_array($contributed) ? $contributed : [] as $name => $value) {
                    $name = (string) $name;
                    if ($name !== '' && is_scalar($value)) {
                        $attributes[$name] = $value;
                    }
                }
            }
        } finally {
            $this->running = false;
        }

        return $attributes;
    }

    private function reportOnce(Throwable $e): void
    {
        if (! $this->failureReported) {
            $this->failureReported = true;
            report($e);
        }
    }
}
