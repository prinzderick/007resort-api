<?php

namespace Tests\Unit;

use App\Domain\Payments\Support\AllocationPlanner;
use PHPUnit\Framework\TestCase;

class AllocationPlannerTest extends TestCase
{
    public function test_tenders_fill_allocations_first_come_first_served_and_sum_exactly(): void
    {
        $plan = AllocationPlanner::plan(
            [['orderId' => 'A', 'amount' => '3000.0000'], ['orderId' => 'B', 'amount' => '6000.0000']],
            ['4000.0000', '5000.0000'],
        );
        $this->assertSame([['orderId' => 'A', 'amount' => '3000.0000'], ['orderId' => 'B', 'amount' => '1000.0000']], $plan[0]);
        $this->assertSame([['orderId' => 'B', 'amount' => '5000.0000']], $plan[1]);
    }

    public function test_awkward_decimals_never_lose_or_invent_a_kobo(): void
    {
        $allocs = [['orderId' => 'A', 'amount' => '33.3333'], ['orderId' => 'B', 'amount' => '33.3333'], ['orderId' => 'C', 'amount' => '33.3334']];
        $tenders = ['0.1000', '99.8999', '0.0001'];
        $plan = AllocationPlanner::plan($allocs, $tenders);
        foreach ($plan as $i => $slices) {
            $sum = '0';
            foreach ($slices as $s) {
                $sum = bcadd($sum, $s['amount'], 4);
            }
            $this->assertSame($tenders[$i], $sum);
        }
        $perOrder = [];
        foreach ($plan as $slices) {
            foreach ($slices as $s) {
                $perOrder[$s['orderId']] = bcadd($perOrder[$s['orderId']] ?? '0', $s['amount'], 4);
            }
        }
        $this->assertSame(['A' => '33.3333', 'B' => '33.3333', 'C' => '33.3334'], $perOrder);
    }

    public function test_mismatched_totals_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AllocationPlanner::plan([['orderId' => 'A', 'amount' => '100.0000']], ['99.9999']);
    }

    public function test_split_evenly_hands_the_remainder_out_in_kobo_and_always_sums_to_the_total(): void
    {
        $this->assertSame(['33.3400', '33.3300', '33.3300'], AllocationPlanner::splitEvenly('100.00', 3));
        $this->assertSame(['0.0100', '0.0100', '0.0000'], AllocationPlanner::splitEvenly('0.02', 3));
        foreach (['100.00', '9999.99', '1.01', '0.07', '123456.78'] as $total) {
            foreach ([1, 2, 3, 4, 7, 10] as $parts) {
                $shares = AllocationPlanner::splitEvenly($total, $parts);
                $sum = '0';
                foreach ($shares as $s) {
                    $sum = bcadd($sum, $s, 4);
                }
                $this->assertSame(bcadd($total, '0', 4), $sum, "{$total} / {$parts}");
                $this->assertLessThanOrEqual(0.0100001, (float) $shares[0] - (float) end($shares), 'shares differ by at most one kobo');
            }
        }
    }

    public function test_change_is_tendered_minus_amount_in_exact_decimals(): void
    {
        $this->assertSame('1000.0000', AllocationPlanner::change('9000.0000', '10000.0000'));
        $this->assertSame('0.3000', AllocationPlanner::change('0.7000', '1.0000'));  // the classic float trap: 1.0 - 0.7
        $this->assertSame('0.0000', AllocationPlanner::change('5.5000', '5.5000'));
    }
}
