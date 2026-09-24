<?php

namespace App\Domain\Cms\Support;

use App\Support\Ids;
use Carbon\CarbonImmutable;

/** Small conversions between DB rows (BINARY(16), DATETIME(6) strings) and API values. */
final class Rows
{
    public static function id(?string $binary): ?string
    {
        return $binary === null ? null : Ids::fromBinary($binary);
    }

    public static function bin(?string $uuid): ?string
    {
        return $uuid === null || $uuid === '' ? null : Ids::toBinary($uuid);
    }

    /** MySQL DATETIME(6) (UTC) -> `2026-10-03T17:00:00.000Z`. */
    public static function iso(?string $mysql): ?string
    {
        if ($mysql === null) {
            return null;
        }

        return self::carbon($mysql)->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function carbon(string $mysql): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(str_contains($mysql, '.') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s', $mysql, 'UTC');
    }

    public static function db(CarbonImmutable|\DateTimeInterface|null $t): ?string
    {
        return $t === null ? null : CarbonImmutable::instance($t)->utc()->format('Y-m-d H:i:s.u');
    }

    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    /** @return list<mixed> */
    public static function json(?string $json): array
    {
        return $json === null ? [] : (array) json_decode($json, true);
    }

    /** @return array<string, mixed>|list<mixed> */
    public static function jsonObj(?string $json): array
    {
        return $json === null ? [] : (array) json_decode($json, true);
    }

    public static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
