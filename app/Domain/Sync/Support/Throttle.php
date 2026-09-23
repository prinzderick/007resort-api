<?php

namespace App\Domain\Sync\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Cheap "run at most every N seconds" gate for sub-minute scheduler tasks (Redis SET NX EX). */
final class Throttle
{
    public static function due(string $name, int $seconds): bool
    {
        try {
            return Cache::add('sync:tick:'.$name, 1, max(1, $seconds));
        } catch (Throwable) {
            return false; // Redis down: skip this tick; data is safe in MySQL and the next tick retries
        }
    }
}
