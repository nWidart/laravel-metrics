<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Nwidart\Metrics\MetricsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MetricsServiceProvider::class];
    }
}
