<?php

namespace App\Support\Demo;

use Ramsey\Uuid\Uuid;

/**
 * Deterministic ids for demo data (UUIDv5), identical on every machine and every re-seed, so clients, docs and other agents
 * can hard-code them in dev:  DemoIds::facility('RESTAURANT'), DemoIds::staff('wait1'), DemoIds::site() ...
 */
final class DemoIds
{
    public const NAMESPACE = '6f0c4a3e-7b1d-4f6a-9c1e-007000000d01';

    public static function of(string $name): string
    {
        return strtolower(Uuid::uuid5(self::NAMESPACE, $name)->toString());
    }

    public static function org(): string
    {
        return self::of('org');
    }

    public static function site(): string
    {
        return self::of('site');
    }

    public static function facility(string $code): string
    {
        return self::of('facility:'.$code);
    }

    public static function operatingPoint(string $facilityCode, string $code): string
    {
        return self::of("op:{$facilityCode}:{$code}");
    }

    public static function resource(string $facilityCode, string $code): string
    {
        return self::of("resource:{$facilityCode}:{$code}");
    }

    public static function staff(string $username): string
    {
        return self::of('staff:'.$username);
    }

    public static function device(string $code): string
    {
        return self::of('device:'.$code);
    }
}
