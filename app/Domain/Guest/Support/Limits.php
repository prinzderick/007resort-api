<?php

namespace App\Domain\Guest\Support;

use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\RateLimiter;

/** Redis-backed fixed-window limits for the guest endpoints (keys are hashed: no PII in Redis keys). */
final class Limits
{
    /** Count one attempt against `$key`; 429 `rate_limited` (+ Retry-After) when over `$max` per `$seconds`. */
    public static function hit(string $bucket, string $key, int $max, int $seconds): void
    {
        $k = 'guest:'.$bucket.':'.hash('sha256', $key);
        if (RateLimiter::tooManyAttempts($k, $max)) {
            throw ApiProblem::tooManyRequests('Too many attempts. Please wait a little and try again.', max(1, RateLimiter::availableIn($k)));
        }
        RateLimiter::hit($k, $seconds);
    }

    /** Only check (no count), used to refuse before doing expensive work. */
    public static function assertNotLimited(string $bucket, string $key, int $max): void
    {
        $k = 'guest:'.$bucket.':'.hash('sha256', $key);
        if (RateLimiter::tooManyAttempts($k, $max)) {
            throw ApiProblem::tooManyRequests('Too many attempts. Please wait a little and try again.', max(1, RateLimiter::availableIn($k)));
        }
    }
}
