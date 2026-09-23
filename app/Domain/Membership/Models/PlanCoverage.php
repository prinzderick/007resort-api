<?php

namespace App\Domain\Membership\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;

class PlanCoverage extends Model
{
    use HasUuidV7;

    protected $table = 'plan_coverage';

    public $timestamps = false;

    protected array $uuidColumns = ['plan_id', 'facility_unit_id'];
}
