<?php

namespace App\Domain\Cms\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A site-relative path (`/events`) or an absolute http(s) URL. Blocks `javascript:`, `data:`, protocol-relative `//host`. */
final class SafeLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 500 || ! self::ok($value)) {
            $fail('The :attribute must be a /relative path or an http(s) URL.');
        }
    }

    public static function ok(string $v): bool
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $v) === 1) {
            return false;
        }
        if (str_starts_with($v, '/')) {
            return ! str_starts_with($v, '//') && ! str_contains($v, '\\');
        }

        return preg_match('#^https?://[^/\s]+#i', $v) === 1 && filter_var($v, FILTER_VALIDATE_URL) !== false;
    }
}
