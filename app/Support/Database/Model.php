<?php

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base model for R007 tables: DATETIME(6) UTC, ISO-8601 (`...Z`) serialization, unguarded
 * mass assignment (validate in requests/services, never trust `$request->all()` blindly).
 */
abstract class Model extends EloquentModel
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function serializeDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
