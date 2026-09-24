<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PayableSubjectResolver;

/** Default until the Booking / Membership modules bind their own resolver. */
class NullPayableSubjectResolver implements PayableSubjectResolver
{
    public function resolve(string $type, string $id): ?array
    {
        return null;
    }
}
