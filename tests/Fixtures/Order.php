<?php

declare(strict_types=1);

namespace Nwidart\Metrics\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];
}
