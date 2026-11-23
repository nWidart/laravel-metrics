<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/** An Octane RequestReceived listener that queries, as a tenancy package does to find the tenant. */
final class LookUpTenant
{
    public function handle(): void
    {
        DB::select('select 1');
    }
}
