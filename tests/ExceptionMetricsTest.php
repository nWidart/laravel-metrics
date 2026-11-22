<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use LogicException;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExceptionMetricsTest extends TestCase
{
    #[Test]
    public function reported_exceptions_are_counted_by_class(): void
    {
        report(new RuntimeException('first'));
        report(new RuntimeException('second'));
        report(new LogicException('third'));
        report(new class('anonymous') extends Exception {});
        $this->app->terminate();

        $this->assertSame([
            'Exception@anonymous' => 1,
            LogicException::class => 1,
            RuntimeException::class => 2,
        ], $this->counts());
    }

    #[Test]
    public function an_exception_that_is_not_reported_is_not_counted(): void
    {
        Route::get('/missing', fn () => throw new NotFoundHttpException);
        Route::get('/broken', fn () => throw new RuntimeException('broken'));

        // JSON: an HTML error page renders views, which the test does not need.
        $this->getJson('/missing')->assertNotFound();
        $this->getJson('/broken')->assertServerError();

        $this->assertSame([RuntimeException::class => 1], $this->counts());
    }

    #[Test]
    #[DefineEnvironment('disableExceptions')]
    public function it_can_be_turned_off(): void
    {
        report(new RuntimeException('ignored'));
        $this->app->terminate();

        $this->assertSame([], $this->counts());
    }

    protected function disableExceptions(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.exceptions', false);
    }

    /**
     * @return array<string, int|float>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->collect() as $metric) {
            if ($metric->name === 'laravel.exceptions') {
                $this->assertInstanceOf(Sum::class, $metric->data);
                foreach ($metric->data->dataPoints as $point) {
                    $counts[(string) $point->attributes->get('exception.type')] = $point->value;
                }
            }
        }
        ksort($counts);

        return $counts;
    }
}
