<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests;

use PHPUnit\Framework\Attributes\Test;

final class MetricsServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_default_configuration(): void
    {
        $this->assertTrue(config('metrics.enabled'));
        $this->assertSame('redis', config('metrics.store.driver'));
        $this->assertSame(2000, config('metrics.cardinality_limit'));
    }
}
