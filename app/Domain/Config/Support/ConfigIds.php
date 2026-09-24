<?php

namespace App\Domain\Config\Support;

use Ramsey\Uuid\Uuid;

/** Deterministic ids for composite-key config rows (so ConfigurationUpdated can address them as one entity on both nodes). */
final class ConfigIds
{
    private const NAMESPACE = '6f0c4a3e-7b1d-4f6a-9c1e-007000000002';

    public static function pair(string $a, string $b): string
    {
        return strtolower(Uuid::uuid5(self::NAMESPACE, strtolower($a).'|'.strtolower($b))->toString());
    }
}
