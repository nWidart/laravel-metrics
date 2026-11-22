<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class ShipOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public bool $fails = false) {}

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException('The carrier is down.');
        }
    }
}
