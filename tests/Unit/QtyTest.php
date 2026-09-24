<?php

namespace Tests\Unit;

use App\Domain\Inventory\Support\Qty;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QtyTest extends TestCase
{
    public function test_decimal_string_arithmetic_is_exact(): void
    {
        $this->assertSame('0.3000', Qty::add('0.1', '0.2'));
        $this->assertSame('-1.5000', Qty::neg('1.5'));
        $this->assertSame('2.5000', Qty::abs('-2.5'));
        $this->assertTrue(Qty::isZero('0.0000'));
        $this->assertSame(-1, Qty::cmp('0.0001', '0.0002'));
    }

    public function test_floats_and_garbage_are_rejected(): void
    {
        foreach ([1.5, '1,5', 'abc', '1.23456', ''] as $bad) {
            try {
                Qty::normalize($bad);
                $this->fail('accepted '.var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
