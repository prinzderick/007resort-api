<?php

namespace App\Domain\Sync\Support;

use Closure;

/** Exponential backoff with "equal jitter": min(cap, base * 2^attempt) scaled into [50%, 100%]. */
final class Backoff
{
    /** @param Closure(): float|null $random returns [0,1); injectable for tests */
    public static function seconds(int $attempt, float $base, float $cap, ?Closure $random = null): float
    {
        $ceiling = min($cap, $base * (2 ** min(max($attempt, 0), 30)));
        $r = $random ? $random() : (mt_rand() / (mt_getrandmax() + 1));

        return round($ceiling * (0.5 + 0.5 * $r), 3);
    }

    public static function nextAt(int $attempt, float $base, float $cap): string
    {
        return Ts::db(Ts::now()->addMilliseconds((int) (self::seconds($attempt, $base, $cap) * 1000)));
    }
}
