<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

final class ExpiredOrder extends Model
{
    use MassPrunable;

    protected $table = 'orders';

    protected $guarded = [];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('total', '<', 0);
    }
}
