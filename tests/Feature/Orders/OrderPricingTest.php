<?php

namespace Tests\Feature\Orders;

use Illuminate\Support\Facades\DB;

/** Server-side totals through the API, with the ADR-0011 VAT setting off/on. */
class OrderPricingTest extends OrdersTestCase
{
    public function test_vat_disabled_by_default_no_tax_line(): void
    {
        $r = $this->draft(['jollof' => 2, 'water' => 3]);
        $this->assertSame('10500.0000', $r->json('subtotal'));
        $this->assertSame('0.0000', $r->json('taxTotal'));
        $this->assertSame('10500.0000', $r->json('total'));
        $this->assertSame('10500.0000', $r->json('balanceDue'));
        $this->assertSame('0.0000', $r->json('amountPaid'));
        $this->assertSame('4500.0000', $r->json('lines.0.unitPrice'));
        $this->assertSame('9000.0000', $r->json('lines.0.lineTotal'));
    }

    public function test_vat_enabled_inclusive_carves_tax_out_of_totals(): void
    {
        $this->f->setVat(true, '7.5', true);
        $r = $this->draft(['jollof' => 1]);
        $this->assertSame('313.9535', $r->json('taxTotal'));
        $this->assertSame('4500.0000', $r->json('total'));
        $this->assertSame('313.9535', $r->json('lines.0.taxAmount'));
    }

    public function test_vat_enabled_exclusive_adds_tax_on_top(): void
    {
        $this->f->setVat(true, '7.5', false);
        $r = $this->draft(['jollof' => 2]);
        $this->assertSame('9000.0000', $r->json('subtotal'));
        $this->assertSame('675.0000', $r->json('taxTotal'));
        $this->assertSame('9675.0000', $r->json('total'));
    }

    public function test_exempt_product_pays_no_vat_and_a_product_rate_overrides_the_default(): void
    {
        $this->f->setVat(true, '7.5', false);
        DB::table('product')->where('id', hex2bin(str_replace('-', '', $this->f->products['water'])))->update(['tax_exempt' => 1]);
        $r = $this->draft(['water' => 2]);
        $this->assertSame('0.0000', $r->json('taxTotal'));
        $this->assertSame('1000.0000', $r->json('total'));
    }

    public function test_line_snapshot_survives_a_later_price_change(): void
    {
        $r = $this->draft(['jollof' => 1]);
        DB::table('price')->update(['amount' => '9999.0000']);
        $again = $this->api('waiter', 'GET', '/orders/'.$r->json('id'));
        $this->assertSame('4500.0000', $again->json('lines.0.unitPrice'));
        $this->assertSame('4500.0000', $again->json('total'));
    }

    public function test_client_cannot_submit_a_price(): void
    {
        $r = $this->api('waiter', 'POST', '/orders', ['facilityId' => $this->f->restaurant->id, 'lines' => [['productId' => $this->f->products['jollof'], 'quantity' => 1, 'unitPrice' => '1.00']]]);
        $this->assertSame('4500.0000', $r->json('total'));
    }
}
