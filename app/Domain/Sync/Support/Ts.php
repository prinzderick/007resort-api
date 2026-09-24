<?php

namespace App\Domain\Sync\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** UTC DATETIME(6) helpers. Always bind PHP-side timestamps (never NOW()) so tests can time-travel. */
final class Ts
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function db(?CarbonInterface $at = null): string
    {
        return ($at ?? self::now())->utc()->format('Y-m-d H:i:s.u');
    }

    public static function parse(string $db): CarbonImmutable
    {
        return CarbonImmutable::parse($db, 'UTC');
    }

    public static function iso(?string $db): ?string
    {
        return $db === null ? null : self::parse($db)->format('Y-m-d\TH:i:s.u\Z');
    }
}
