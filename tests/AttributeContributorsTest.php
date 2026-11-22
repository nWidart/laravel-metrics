<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nwidart\Metrics\AttributeContributors;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

final class AttributeContributorsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    #[Test]
    public function a_contributor_adds_attributes_and_wins_over_a_built_in_one(): void
    {
        $this->contributors()->add('http.server.request.duration', fn (RequestHandled $event): array => [
            'tenant' => $event->request->header('X-Tenant', 'none'),
            'url.scheme' => 'tenant-scheme',
        ]);
        Route::get('/orders', fn () => 'ok');

        $this->get('/orders', ['X-Tenant' => 'acme'])->assertOk();

        $attributes = $this->histogramPoints('http.server.request.duration')[0]->attributes;
        $this->assertSame('acme', $attributes->get('tenant'));
        $this->assertSame('tenant-scheme', $attributes->get('url.scheme'));
        $this->assertSame('/orders', $attributes->get('http.route'));
    }

    #[Test]
    public function a_contributor_that_throws_is_skipped_and_reported_once(): void
    {
        $reported = [];
        $handler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $handler->reportable(function (Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $this->contributors()->add('laravel.cache.operations', fn () => throw new RuntimeException('broken contributor'));
        $this->contributors()->add('laravel.cache.operations', fn (CacheEvent $event): array => ['list' => [1], 'store.kind' => 'memory', '' => 'empty']);
        Cache::store('array')->get('a');
        Cache::store('array')->get('b');
        $this->app->terminate();

        $this->assertSame(['broken contributor'], $reported);
        $points = $this->sumPoints('laravel.cache.operations');
        $this->assertCount(1, $points);
        $this->assertSame(2, $points[0]['value']);
        $this->assertSame('memory', $points[0]['attributes']['store.kind']);
        $this->assertArrayNotHasKey('list', $points[0]['attributes']);
    }

    #[Test]
    public function the_measurements_a_contributor_causes_get_no_contributed_attributes(): void
    {
        $this->contributors()->add('db.client.operation.duration', function (QueryExecuted $event): array {
            // A query inside the contributor would otherwise call it again, without end.
            DB::select('select 2');

            return ['module' => 'shop'];
        });

        DB::select('select 1');
        $this->app->terminate();

        $modules = array_map(fn ($point) => $point->attributes->get('module'), $this->histogramPoints('db.client.operation.duration'));
        sort($modules);
        $this->assertSame([null, 'shop'], $modules);
    }

    #[Test]
    public function an_exception_contributor_receives_the_exception(): void
    {
        $this->contributors()->add('laravel.exceptions', fn (Throwable $e): array => ['module' => $e->getMessage()]);

        report(new RuntimeException('billing'));
        $this->app->terminate();

        $this->assertSame('billing', $this->sumPoints('laravel.exceptions')[0]['attributes']['module']);
    }

    private function contributors(): AttributeContributors
    {
        return $this->app->make(AttributeContributors::class);
    }

    /**
     * @return list<array{attributes: array<string, mixed>, value: int|float}>
     */
    private function sumPoints(string $name): array
    {
        $points = [];
        foreach ($this->collect() as $metric) {
            if ($metric->name === $name && $metric->data instanceof Sum) {
                foreach ($metric->data->dataPoints as $point) {
                    $points[] = ['attributes' => $point->attributes->toArray(), 'value' => $point->value];
                }
            }
        }

        return $points;
    }
}
