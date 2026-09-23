<?php

namespace App\Domain\Payments\Support;

use InvalidArgumentException;

/**
 * Pure money maths for split / multi-order payments (bcmath on decimal strings, scale 4; no floats anywhere).
 */
final class AllocationPlanner
{
    /**
     * Spread tenders over allocations, first-come-first-served, so that every tender's per-order slices add up exactly
     * to the tender amount and every order's slices add up exactly to its allocation. Requires sum(tenders) == sum(allocations).
     *
     * @param  list<array{orderId: string, amount: string}>  $allocations
     * @param  list<string>  $tenderAmounts
     * @return list<list<array{orderId: string, amount: string}>> one slice list per tender, same order as $tenderAmounts
     */
    public static function plan(array $allocations, array $tenderAmounts): array
    {
        $totalAlloc = '0';
        foreach ($allocations as $a) {
            $totalAlloc = bcadd($totalAlloc, $a['amount'], 4);
        }
        $totalTender = '0';
        foreach ($tenderAmounts as $t) {
            $totalTender = bcadd($totalTender, $t, 4);
        }
        if (bccomp($totalAlloc, $totalTender, 4) !== 0) {
            throw new InvalidArgumentException("Allocations ({$totalAlloc}) must equal tenders ({$totalTender}).");
        }

        $remaining = array_map(fn ($a) => $a['amount'], $allocations); // per allocation index
        $i = 0;
        $out = [];
        foreach ($tenderAmounts as $tenderAmount) {
            $need = $tenderAmount;
            $slices = [];
            while (bccomp($need, '0', 4) > 0) {
                while ($i < count($remaining) && bccomp($remaining[$i], '0', 4) === 0) {
                    $i++;
                }
                $take = bccomp($need, $remaining[$i], 4) <= 0 ? $need : $remaining[$i];
                $slices[] = ['orderId' => $allocations[$i]['orderId'], 'amount' => bcadd($take, '0', 4)];
                $remaining[$i] = bcsub($remaining[$i], $take, 4);
                $need = bcsub($need, $take, 4);
            }
            $out[] = $slices;
        }

        return $out;
    }

    /**
     * Split a bill into `$parts` near-equal shares in minor units (default kobo = 0.01) so the shares add up EXACTLY to the
     * total: the remainder is handed out one unit at a time to the first shares. E.g. 100.00 / 3 = 33.34, 33.33, 33.33.
     *
     * @return list<string>
     */
    public static function splitEvenly(string $total, int $parts, string $unit = '0.01'): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('parts must be >= 1');
        }
        $scale = 4;
        // work in integer "units": total / unit must be integral
        $units = bcdiv($total, $unit, 0);
        if (bccomp(bcmul($units, $unit, $scale), $total, $scale) !== 0) {
            throw new InvalidArgumentException("{$total} is not a whole number of {$unit} units.");
        }
        $base = bcdiv($units, (string) $parts, 0);
        $rem = (int) bcmod($units, (string) $parts);
        $out = [];
        for ($k = 0; $k < $parts; $k++) {
            $u = $k < $rem ? bcadd($base, '1', 0) : $base;
            $out[] = bcmul($u, $unit, $scale);
        }

        return $out;
    }

    /** Change owed on a cash tender: tendered - amount (never negative; caller validates tendered >= amount). */
    public static function change(string $amount, string $tendered): string
    {
        return bcsub($tendered, $amount, 4);
    }
}
