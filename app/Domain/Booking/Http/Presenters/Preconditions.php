<?php

namespace App\Domain\Booking\Http\Presenters;

use App\Support\Api\Concurrency;
use Illuminate\Http\Request;

/** `If-Match` <-> `rowVersion` via the foundation helper (missing -> 428, unparseable -> 412 concurrency_conflict). */
final class Preconditions
{
    public static function rowVersion(Request $request): int
    {
        return (int) Concurrency::ifMatch($request, true);
    }

    public static function etag(int $rowVersion): string
    {
        return Concurrency::etag($rowVersion);
    }
}
