<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class HttpServerMetricsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('/users/{user}', fn (string $user) => "user {$user}");
        $router->get('/', fn () => 'home');
        $router->get('/boom', fn () => throw new RuntimeException('boom'));
        $router->get('/up', fn () => 'up');
        $router->get('/probe/live', fn () => 'live')->name('probe.live');
    }

    #[Test]
    public function it_records_the_request_duration_with_the_route_template(): void
    {
        $this->get('/users/1')->assertOk();
        $this->get('/users/2')->assertOk();
        $this->get('/')->assertOk();

        $byRoute = [];
        foreach ($this->histogramPoints('http.server.request.duration') as $point) {
            $byRoute[$point->attributes->get('http.route')] = $point;
        }
        ksort($byRoute);

        $this->assertSame(['/', '/users/{user}'], array_keys($byRoute));
        $point = $byRoute['/users/{user}'];
        $this->assertSame(2, $point->count);
        $this->assertSame([
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
            'http.route' => '/users/{user}',
            'network.protocol.version' => '1.1',
            'url.scheme' => 'http',
        ], $this->sorted($point->attributes->toArray()));
        $this->assertGreaterThan(0.0, $point->sum);
        $this->assertLessThan(10.0, $point->sum);
        $this->assertSame([0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0], $point->explicitBounds);
    }

    #[Test]
    public function an_unmatched_request_has_no_route_and_an_error_has_an_error_type(): void
    {
        $this->get('/missing')->assertNotFound();
        $this->withoutExceptionHandling([RuntimeException::class]);
        $this->get('/boom')->assertStatus(500);

        $byStatus = [];
        foreach ($this->histogramPoints('http.server.request.duration') as $point) {
            $byStatus[$point->attributes->get('http.response.status_code')] = $this->sorted($point->attributes->toArray());
        }

        $this->assertArrayNotHasKey('http.route', $byStatus[404]);
        $this->assertArrayNotHasKey('error.type', $byStatus[404]);
        $this->assertSame('/boom', $byStatus[500]['http.route']);
        $this->assertSame('500', $byStatus[500]['error.type']);
    }

    #[Test]
    public function an_unknown_method_becomes_other(): void
    {
        $this->call('PURGE', '/');

        $points = $this->histogramPoints('http.server.request.duration');
        $this->assertCount(1, $points);
        $this->assertSame('_OTHER', $points[0]->attributes->get('http.request.method'));
    }

    #[Test]
    #[DefineEnvironment('ignoreProbes')]
    public function ignored_routes_are_not_recorded_by_name_or_by_uri(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/probe/live')->assertOk();
        $this->get('/')->assertOk();

        $routes = array_map(fn ($point) => $point->attributes->get('http.route'), $this->histogramPoints('http.server.request.duration'));
        $this->assertSame(['/'], $routes);
    }

    protected function ignoreProbes(Application $app): void
    {
        $app['config']->set('metrics.ignore_routes', ['/up', 'probe.*']);
    }

    #[Test]
    #[DefineEnvironment('disableHttp')]
    public function it_can_be_turned_off(): void
    {
        $this->get('/')->assertOk();

        $this->assertSame([], $this->histogramPoints('http.server.request.duration'));
    }

    protected function disableHttp(Application $app): void
    {
        $app['config']->set('metrics.instrumentation.http', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sorted(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }
}
