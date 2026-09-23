<?php

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Services\Pricing;
use PHPUnit\Framework\TestCase;

/** Decimal maths (no floats): VAT off, inclusive, exclusive, exempt, discounts, rounding. */
class PricingMathTest extends TestCase
{
    public function test_vat_off_has_no_tax(): void
    {
        $l = Pricing::line('4500', 2, '0', true);
        $this->assertSame(['gross' => '9000.0000', 'discount' => '0.0000', 'tax' => '0.0000', 'total' => '9000.0000'], $l);
    }

    public function test_inclusive_vat_is_carved_out_of_the_price(): void
    {
        $l = Pricing::line('4500', 1, '7.5', true);
        $this->assertSame('313.9535', $l['tax']); // 4500 * 7.5 / 107.5
        $this->assertSame('4500.0000', $l['total']);
    }

    public function test_exclusive_vat_is_added_on_top(): void
    {
        $l = Pricing::line('4500', 1, '7.5', false);
        $this->assertSame('337.5000', $l['tax']);
        $this->assertSame('4837.5000', $l['total']);
    }

    public function test_discount_reduces_the_taxable_amount_and_is_capped_at_gross(): void
    {
        $l = Pricing::line('1000', 3, '7.5', false, '500');
        $this->assertSame('3000.0000', $l['gross']);
        $this->assertSame('2500.0000', bcsub($l['total'], $l['tax'], 4));
        $this->assertSame('187.5000', $l['tax']);
        $comp = Pricing::line('1000', 3, '7.5', false, '99999');
        $this->assertSame('3000.0000', $comp['discount']);
        $this->assertSame('0.0000', $comp['total']);
    }

    public function test_no_binary_float_drift_on_awkward_amounts(): void
    {
        $l = Pricing::line('0.1', 3, '0', true);
        $this->assertSame('0.3000', $l['total']);
        $l = Pricing::line('19.99', 7, '7.5', true);
        $this->assertSame('139.9300', $l['total']);
        $this->assertSame('9.7626', $l['tax']); // 139.93 * 7.5 / 107.5
    }
}
