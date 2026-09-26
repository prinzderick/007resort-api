<?php

namespace App\Domain\Guest\Support;

/**
 * Pure normalisation of guest contact data (docs/GUEST_CHECKOUT.md s3). Every method returns null for junk; the caller turns null into a 422.
 * Nigerian defaults: 0803... -> +234803..., 234803... / +234803... accepted. Other countries: `+` E.164 when international numbers are allowed.
 */
final class ContactNormalizer
{
    public static function name(?string $v): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        $len = mb_strlen($v);

        return $len >= 2 && $len <= 120 && preg_match('/\p{L}/u', $v) === 1 ? $v : null;
    }

    public static function email(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));
        if ($v === '' || strlen($v) > 254 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $v;
    }

    public static function phone(?string $v, bool $international = true): ?string
    {
        $raw = trim((string) $v);
        if ($raw === '' || preg_match('/^[+\d\s().\-]+$/', $raw) !== 1) {
            return null;
        }
        $plus = str_starts_with($raw, '+');
        $d = preg_replace('/\D/', '', $raw);
        if ($d === '') {
            return null;
        }
        if (! $plus && str_starts_with($d, '00')) { // 00234... international dialling prefix
            $plus = true;
            $d = substr($d, 2);
        }
        if ($plus) {
            if (str_starts_with($d, '234')) {
                return self::ng(ltrim(substr($d, 3), '0') === substr($d, 3) ? substr($d, 3) : substr($d, 4)); // "+2340803..." is a common typo
            }

            return $international && preg_match('/^[1-9]\d{7,14}$/', $d) === 1 ? '+'.$d : null;
        }
        if (str_starts_with($d, '234') && strlen($d) === 13) {
            return self::ng(substr($d, 3));
        }
        if (str_starts_with($d, '0')) {
            return self::ng(substr($d, 1));
        }

        return strlen($d) === 10 ? self::ng($d) : null; // 803 123 4567 without the leading 0
    }

    private static function ng(string $national): ?string
    {
        return preg_match('/^[789]\d{9}$/', $national) === 1 ? '+234'.$national : null;
    }

    /** Stable, non-reversible fingerprint for logs / rate-limit keys (never log the raw value). */
    public static function fingerprint(?string $v): string
    {
        return substr(hash_hmac('sha256', strtolower((string) $v), (string) config('app.key')), 0, 16);
    }
}
