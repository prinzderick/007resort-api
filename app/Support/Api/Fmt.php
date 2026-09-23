<?php

namespace App\Support\Api;

use App\Support\Ids;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/** Row -> JSON formatting helpers shared by the query-builder based services (BINARY(16), DATETIME(6), DECIMAL). */
final class Fmt
{
    /** BINARY(16) -> canonical uuid (null-safe). */
    public static function u(?string $binary): ?string
    {
        return $binary === null ? null : Ids::fromBinary($binary);
    }

    /** canonical uuid -> BINARY(16) (null-safe). */
    public static function b(?string $uuid): ?string
    {
        return $uuid === null ? null : Ids::toBinary($uuid);
    }

    /** MySQL DATETIME(6) (UTC) -> ISO-8601 `...Z`. */
    public static function ts(?string $db): ?string
    {
        if ($db === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', str_contains($db, '.') ? $db : $db.'.000000', 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Current UTC time as a DATETIME(6) string. */
    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    /** DECIMAL column -> 4dp decimal string. */
    public static function money(string|int|null $value): string
    {
        return Money::normalize($value ?? '0');
    }

    /** ISO-8601 client timestamp -> DATETIME(6) UTC (null when absent/unparseable). */
    public static function clientTs(mixed $iso): ?string
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            return null;
        }
    }
}
