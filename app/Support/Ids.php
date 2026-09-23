<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Identifier helpers (ADR-0003): UUIDv7 everywhere, BINARY(16) in MySQL, canonical
 * lowercase 8-4-4-4-12 string in code and in the API.
 */
final class Ids
{
    /** New time-ordered UUIDv7 as canonical string. */
    public static function uuid7(): string
    {
        return strtolower(Str::uuid7()->toString());
    }

    /** Accepts canonical (36 chars) or bare 32-hex UUID strings. */
    public static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && (bool) preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $value);
    }

    /** Canonical UUID string -> 16 raw bytes for BINARY(16) columns / DB::table() bindings. */
    public static function toBinary(string $uuid): string
    {
        if (! self::isUuid($uuid)) {
            throw new InvalidArgumentException("Not a UUID: {$uuid}");
        }

        return hex2bin(str_replace('-', '', $uuid));
    }

    /** 16 raw bytes -> canonical UUID string. */
    public static function fromBinary(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new InvalidArgumentException('Binary UUID must be exactly 16 bytes.');
        }
        $hex = bin2hex($binary);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** Normalise anything UUID-ish (canonical, 32-hex, or 16 raw bytes) to canonical form. */
    public static function normalize(string $value): string
    {
        return strlen($value) === 16 ? self::fromBinary($value) : strtolower(self::fromBinary(self::toBinary($value)));
    }
}
