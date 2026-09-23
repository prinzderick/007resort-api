<?php

namespace App\Domain\Booking\Http\Presenters;

use App\Support\Http\ApiProblem;
use Illuminate\Http\Request;

/** `If-Match` <-> `rowVersion` (contract: ETag `"v3"`; missing -> 428, mismatch -> 412 concurrency_conflict). */
final class Preconditions
{
    public static function rowVersion(Request $request): int
    {
        $raw = trim((string) $request->header('If-Match', ''));
        if ($raw === '') {
            throw new ApiProblem(428, 'concurrency_conflict', 'If-Match is required (use the ETag from the last response).', 'Precondition required');
        }
        if (! preg_match('/^(?:W\/)?"?v?(\d+)"?$/', $raw, $m)) {
            throw ApiProblem::badRequest('validation_failed', 'If-Match must be an ETag such as "v3".');
        }

        return (int) $m[1];
    }

    public static function etag(int $rowVersion): string
    {
        return '"v'.$rowVersion.'"';
    }
}
