<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Fomvasss\Billing\Contracts\Payable;
use Illuminate\Database\Eloquent\Model;

class TestOrder extends Model implements Payable
{
    protected $table = 'test_orders';

    protected $guarded = ['id'];
}
