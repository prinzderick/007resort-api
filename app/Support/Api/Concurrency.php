<?php

namespace App\Support\Api;

use App\Support\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ETag / If-Match helpers. ETag is the aggregate's row_version, e.g. `"v3"`. */
final class Concurrency
{
    public static function etag(int|string $rowVersion): string
    {
        return '"v'.$rowVersion.'"';
    }

    /** Parse `If-Match: "v3"` / `W/"v3"` / `3`. 428 when missing (and required), 412 when unparseable. */
    public static function ifMatch(Request $request, bool $required = true): ?int
    {
        $h = $request->header('If-Match');
        if ($h === null || trim($h) === '') {
            if ($required) {
                throw new ApiProblem(428, 'concurrency_conflict', 'The If-Match header (the aggregate ETag) is required for this request.', 'Precondition required');
            }

            return null;
        }
        if (preg_match('/^\s*(?:W\/)?"?v?(\d+)"?\s*$/', $h, $m) !== 1) {
            throw new ApiProblem(412, 'concurrency_conflict', 'The If-Match header is not a valid ETag.', 'Precondition failed');
        }

        return (int) $m[1];
    }

    /** Compare the caller's version with the locked row's version. */
    public static function assertVersion(int $current, ?int $expected, string $what = 'resource'): void
    {
        if ($expected !== null && $expected !== $current) {
            throw new ApiProblem(412, 'concurrency_conflict', "The {$what} was changed by someone else (expected v{$expected}, current v{$current}). Reload and retry.", 'Precondition failed', ['currentRowVersion' => $current]);
        }
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200, ?int $rowVersion = null, array $headers = []): JsonResponse
    {
        if ($rowVersion !== null) {
            $headers['ETag'] = self::etag($rowVersion);
        }

        return response()->json($data, $status, $headers);
    }

    /** JSON list with a strong ETag over the body; honours If-None-Match (304). */
    public static function cacheable(Request $request, array $data): Response
    {
        $etag = '"'.substr(hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response()->json($data, 200, ['ETag' => $etag]);
    }
}
