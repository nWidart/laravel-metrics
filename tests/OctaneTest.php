<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Nwidart\Metrics\Facades\Metrics;
use Nwidart\Metrics\MetricsServiceProvider;
use Nwidart\Metrics\Tests\Fixtures\LookUpTenant;
use Nwidart\Metrics\Tests\Fixtures\Order;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Runs requests, tasks and ticks through Octane's own worker, in this process: one application
 * booted once, a clone of it per request, the same lifecycle events in the same order. Swoole,
 * RoadRunner and FrankenPHP only differ in how they hand a request to this worker.
 */
final class OctaneTest extends TestCase
{
    /** @var list<int> spl_object_id of the meter provider each request saw */
    private static array $providers = [];

    protected function getPackageProviders($app): array
    {
        return [OctaneServiceProvider::class, MetricsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Octane warms the encrypter when the worker boots.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/orders', function () {
            self::$providers[] = spl_object_id(app(MeterProviderInterface::class));
            Metrics::meter()->createCounter('orders.listed')->add(1, array_filter(['page' => request()->query('page')]));
            Order::all();
            // After the response is sent: the query belongs to no request's count, and the counter
            // is merged with the request.
            app()->terminating(function (): void {
                DB::select('select 1');
                Metrics::meter()->createCounter('orders.terminated')->add(1);
            });

            return 'ok';
        });
        Route::get('/up', fn () => DB::select('select 1') === [] ? 'down' : 'up');
        Route::get('/broken', fn () => throw new RuntimeException('broken'));
        // A tenant connection, as tenancy packages switch it: the same name, another database.
        Route::get('/tenants/{tenant}', function (string $tenant) {
            config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => self::tenantDatabase($tenant), 'prefix' => '']]);
            DB::purge('tenant');
            DB::connection('tenant')->select('select 1');

            return 'ok';
        });
    }

    protected function withoutDatabaseInstrumentation($app): void
    {
        $app['config']->set('metrics.instrumentation.db', false);
        $app['config']->set('metrics.instrumentation.eloquent', false);
    }

    /** An application listener that queries before the package's, as a tenancy package does. */
    protected function withATenantLookup($app): void
    {
        $app['config']->set('octane.listeners.'.RequestReceived::class, [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            LookUpTenant::class,
        ]);
    }

    protected function ignoringUp($app): void
    {
        $app['config']->set('metrics.ignore_routes', ['/up']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$providers = [];

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->integer('total');
            $table->timestamps();
            $table->softDeletes();
        });
        Order::create(['total' => 10]);
        Order::create(['total' => 20]);
    }

    #[Test]
    public function each_request_is_merged_into_the_store_when_it_terminates(): void
    {
        $worker = $this->worker();

        foreach ([1, 2, 3] as $handled) {
            $this->handle($worker, '/orders');

            $points = $this->histogramPoints('http.server.request.duration');
            $this->assertCount(1, $points);
            $this->assertSame($handled, $points[0]->count);
            $this->assertSame([[[], $handled]], $this->sums('orders.listed'));
            $this->assertSame([[[], $handled]], $this->sums('orders.terminated'));
        }
    }

    #[Test]
    #[DefineEnvironment('withoutDatabaseInstrumentation')]
    public function the_meter_provider_is_built_once_per_worker_even_when_a_request_asks_for_it_first(): void
    {
        $worker = $this->worker();

        $this->handle($worker, '/orders');
        $this->handle($worker, '/orders');

        $this->assertCount(2, self::$providers);
        $this->assertSame(self::$providers[0], self::$providers[1]);
        $this->assertSame(self::$providers[0], spl_object_id($worker->application()->make(MeterProviderInterface::class)));
        $this->assertSame([[[], 2]], $this->sums('orders.listed'));
        $this->assertSame(2, $this->histogramPoints('http.server.request.duration')[0]->count);
    }

    #[Test]
    public function counts_of_one_request_do_not_leak_into_the_next(): void
    {
        $worker = $this->worker();

        $this->handle($worker, '/orders');
        $this->handle($worker, '/orders');
        $this->handle($worker, '/up');

        $queries = [];
        foreach ($this->histogramPoints('laravel.db.queries') as $point) {
            $queries[$point->attributes->get('http.route')] = [$point->count, $point->sum];
        }
        ksort($queries);
        // One query per request: the query each /orders request runs after its response is not counted.
        $this->assertEquals(['/orders' => [2, 2], '/up' => [1, 1]], $queries);
        $this->assertSame([
            [['laravel.eloquent.event' => 'created', 'laravel.eloquent.model' => Order::class], 2],
            [['laravel.eloquent.event' => 'retrieved', 'laravel.eloquent.model' => Order::class], 4],
        ], $this->sums('laravel.eloquent.models'));
    }

    #[Test]
    #[DefineEnvironment('withATenantLookup')]
    public function the_queries_of_application_listeners_count_for_their_request(): void
    {
        $worker = $this->worker();

        $this->handle($worker, '/up');
        $this->handle($worker, '/up');

        $points = $this->histogramPoints('laravel.db.queries');
        $this->assertCount(1, $points);
        $this->assertSame(2, $points[0]->count);
        $this->assertEquals(4, $points[0]->sum);
    }

    #[Test]
    public function each_reported_exception_is_counted_once(): void
    {
        $worker = $this->worker();

        $this->handle($worker, '/broken');
        $this->handle($worker, '/broken');
        $this->handle($worker, '/broken');

        $this->assertSame([[['exception.type' => RuntimeException::class], 3]], $this->sums('laravel.exceptions'));
    }

    #[Test]
    public function a_connection_built_again_for_another_database_gets_its_attributes_again(): void
    {
        $worker = $this->worker();
        touch(self::tenantDatabase('a'));
        touch(self::tenantDatabase('b'));

        try {
            $this->handle($worker, '/tenants/a');
            $this->handle($worker, '/tenants/b');
        } finally {
            unlink(self::tenantDatabase('a'));
            unlink(self::tenantDatabase('b'));
        }

        $namespaces = array_values(array_unique(array_map(fn ($point) => $point->attributes->get('db.namespace'), $this->histogramPoints('db.client.operation.duration'))));
        $this->assertEqualsCanonicalizing([':memory:', self::tenantDatabase('a'), self::tenantDatabase('b')], $namespaces);
    }

    #[Test]
    public function tasks_and_ticks_are_merged_when_they_end(): void
    {
        $worker = $this->worker([
            fn () => Metrics::meter()->createCounter('octane.tasks')->add(1),
            fn () => Metrics::meter()->createCounter('octane.tasks')->add(1),
        ]);

        $worker->runTasks();
        $this->assertSame([[[], 2]], $this->sums('octane.tasks'));

        Metrics::meter()->createCounter('octane.ticks')->add(1);
        $worker->handleTick();
        $this->assertSame([[[], 1]], $this->sums('octane.ticks'));
    }

    #[Test]
    public function a_failed_task_is_merged_with_its_exception(): void
    {
        $worker = $this->worker([
            function (): void {
                Metrics::meter()->createCounter('octane.tasks')->add(1);

                throw new RuntimeException('task failed');
            },
        ]);

        $worker->runTasks();

        $this->assertSame([[[], 1]], $this->sums('octane.tasks'));
        $this->assertSame([[['exception.type' => RuntimeException::class], 1]], $this->sums('laravel.exceptions'));
    }

    #[Test]
    public function what_is_left_is_merged_when_the_worker_stops(): void
    {
        $worker = $this->worker();

        Metrics::meter()->createCounter('octane.left')->add(1);
        $worker->terminate();

        $this->assertSame([[[], 1]], $this->sums('octane.left'));
    }

    #[Test]
    #[DefineEnvironment('ignoringUp')]
    public function recording_keeps_no_memory_from_one_request_to_the_next(): void
    {
        $worker = $this->worker();
        $growth = function (string $uri) use ($worker): int {
            gc_collect_cycles();
            $before = memory_get_usage();
            for ($i = 0; $i < 300; $i++) {
                $this->handle($worker, sprintf($uri, $i));
            }
            gc_collect_cycles();

            return memory_get_usage() - $before;
        };
        $growth('/orders?page=warm-%d');
        $growth('/up');

        // Laravel and the test harness keep a little memory per request on their own: an ignored
        // route, which records nothing, measures it. Every recorded request here has new attributes.
        $recorded = $growth('/orders?page=%d');
        $ignored = $growth('/up');

        $this->assertLessThan(128 * 1024, $recorded - $ignored);
        $this->assertSame(600, $this->histogramPoints('http.server.request.duration')[0]->count);
        $this->assertCount(600, $this->sums('orders.listed'));
    }

    /**
     * Octane's worker, on this test's application, booted as Octane boots it.
     *
     * @param  list<mixed>  $requests  what the client hands the worker: requests or tasks
     */
    private function worker(array $requests = []): FakeWorker
    {
        $factory = new class($this->app) extends ApplicationFactory
        {
            public function __construct(private readonly Application $app) {}

            public function createApplication(array $initialInstances = []): Application
            {
                // The test case has bootstrapped the application already.
                foreach ($initialInstances as $key => $value) {
                    $this->app->instance($key, $value);
                }

                return $this->warm($this->app);
            }
        };

        $worker = new FakeWorker($factory, new FakeClient($requests));
        $worker->boot();

        return $worker;
    }

    private static function tenantDatabase(string $tenant): string
    {
        return sys_get_temp_dir().'/laravel-metrics-octane-'.getmypid().'-tenant-'.$tenant.'.sqlite';
    }

    private function handle(FakeWorker $worker, string $uri): void
    {
        $request = Request::create($uri);
        $worker->handle($request, new RequestContext(['request' => $request]));
    }
}
