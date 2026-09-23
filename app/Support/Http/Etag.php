<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optimistic concurrency via `row_version` (contract: ETag on reads, `If-Match` on updates).
 *   return Etag::json($body, $model->row_version);                  // adds ETag: "3"
 *   Etag::assertMatches($request, $model->row_version);             // 428 if header missing, 412 if stale
 * Both map to problem code `concurrency_conflict`.
 */
final class Etag
{
    public static function make(int|string $rowVersion): string
    {
        return '"'.$rowVersion.'"';
    }

    public static function json(array $body, int|string $rowVersion, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['ETag' => self::make($rowVersion)]);
    }

    public static function assertMatches(Request $request, int|string $rowVersion): void
    {
        $header = $request->header('If-Match');
        if ($header === null || $header === '') {
            throw ApiProblem::concurrencyConflict('If-Match header is required for this update.', 428);
        }
        $given = trim(str_replace('W/', '', $header), " \"'");
        if ($given !== '*' && $given !== (string) $rowVersion) {
            throw ApiProblem::concurrencyConflict('The resource was modified since you read it (row version mismatch).', 412);
        }
    }
}
