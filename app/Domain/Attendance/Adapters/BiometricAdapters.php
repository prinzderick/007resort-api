<?php

namespace App\Domain\Attendance\Adapters;

use App\Domain\Attendance\Contracts\BiometricTerminalAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;

/** Resolves the adapter for a terminal. Swap hardware by binding a different class here (or via the container). */
final class BiometricAdapters
{
    public const MAP = ['ZKTECO_ADMS' => ZktecoAdmsAdapter::class, 'JSON_PUSH' => JsonPushAdapter::class];

    public static function for(AttendanceDevice $device): BiometricTerminalAdapter
    {
        return self::named($device->adapter);
    }

    public static function named(string $key): BiometricTerminalAdapter
    {
        $class = self::MAP[$key] ?? throw new \InvalidArgumentException("Unknown biometric adapter {$key}");

        return app($class);
    }
}
