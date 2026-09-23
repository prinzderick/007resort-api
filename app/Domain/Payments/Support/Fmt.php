<?php

namespace App\Domain\Payments\Support;

use App\Support\Ids;
use Carbon\CarbonImmutable;

/** Small conversion helpers shared by the Payments services (DB rows <-> API values). */
final class Fmt
{
    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    /** DATETIME(6) UTC -> ISO-8601 `...Z` (null-safe). */
    public static function iso(?string $mysql): ?string
    {
        if ($mysql === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', str_contains($mysql, '.') ? $mysql : $mysql.'.000000', 'UTC')->format('Y-m-d\TH:i:s.u\Z');
    }

    /** ISO-8601 (any offset) -> DATETIME(6) UTC, or null when unparsable/absent. */
    public static function fromIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function uuid(?string $binary): ?string
    {
        return $binary === null ? null : Ids::fromBinary($binary);
    }

    public static function bin(?string $uuid): ?string
    {
        return $uuid === null ? null : Ids::toBinary($uuid);
    }

    /** Strict UUIDv7 check (client-supplied ids must be v7). */
    public static function isUuid7(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
