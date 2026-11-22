<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class CountOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $queries = 1) {}

    public function handle(): void
    {
        for ($i = 0; $i < $this->queries; $i++) {
            DB::select('select 1');
        }
    }
}
