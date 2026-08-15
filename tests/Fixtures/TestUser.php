<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Fomvasss\Billing\Concerns\Billable as BillableConcern;
use Fomvasss\Billing\Contracts\Billable;
use Illuminate\Database\Eloquent\Model;

class TestUser extends Model implements Billable
{
    use BillableConcern;

    protected $table = 'test_users';

    protected $guarded = ['id'];
}
