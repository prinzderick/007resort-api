<?php

namespace Tests\Feature\Booking;

use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Services\PaymentService;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingHelpers;
use Tests\Support\OrdersFixture;
use Tests\Support\TestData;
use Tests\TestCase;

/**
 * Runs only when the Orders + Payments modules are both present (i.e. once the stacked PRs are merged): the contract path
 * `POST /bookings/{id}/confirm {tenders}` pays through a real order + Payments (ledger, receipt, cash session rules).
 */
class PaymentsIntegrationTest extends TestCase
{
    use BookingHelpers;

    private array $w;

    private string $token;

    private string $fee;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(PaymentService::class) || ! class_exists(OrderService::class)) {
            $this->markTestSkipped('Payments/Orders modules are not installed on this branch.');
        }
        $this->w = $this->world();
        config(['booking.payment_gateway' => 'auto']);
        $org = Ids::toBinary($this->w['t']['org']);
        $cat = OrdersFixture::insert('product_category', ['organization_id' => $org, 'name' => 'Sports']);
        $list = OrdersFixture::insert('price_list', ['organization_id' => $org, 'name' => 'Standard', 'is_default' => 1]);
        $this->fee = OrdersFixture::insert('product', ['organization_id' => $org, 'category_id' => Ids::toBinary($cat), 'sku' => 'COURT-FEE', 'name' => 'Court fee', 'kind' => 'FEE']);
        DB::table('product_facility')->insert(['product_id' => Ids::toBinary($this->fee), 'facility_unit_id' => Ids::toBinary($this->w['reception']->id)]);
        OrdersFixture::insert('price', ['price_list_id' => Ids::toBinary($list), 'product_id' => Ids::toBinary($this->fee), 'amount' => '5000']);
        // Sports Arena bookings are paid at Reception (operating rule, architecture/05 §4)
        $fc = OrdersFixture::insert('facility_capability', ['facility_unit_id' => Ids::toBinary($this->w['arena']->id), 'capability_code' => 'BOOKING', 'is_enabled' => 1]);
        OrdersFixture::insert('operating_rule', ['facility_capability_id' => Ids::toBinary($fc), 'rule_key' => 'payment_facility_unit_id', 'rule_value' => $this->w['reception']->id]);

        // Reception is a pay-first counter (Orders' OperatingRules default is pay-after-service)
        $pos = OrdersFixture::insert('facility_capability', ['facility_unit_id' => Ids::toBinary($this->w['reception']->id), 'capability_code' => 'POS', 'is_enabled' => 1]);
        OrdersFixture::insert('operating_rule', ['facility_capability_id' => Ids::toBinary($pos), 'rule_key' => 'payment_timing', 'rule_value' => 'PAY_FIRST']);

        [$staff, $this->token] = $this->staffWith($this->w, 'rita', self::BOOKING_PERMS);
        TestData::assign($staff, 'CASHIER', 'FACILITY_UNIT', $this->w['reception']->id);
    }

    public function test_confirm_with_tenders_creates_an_order_captures_payment_and_confirms_atomically(): void
    {
        $r = $this->resource($this->w, ['product_id' => $this->fee]);
        $slot = $this->slot();
        $held = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1], 'customer' => ['name' => 'Chinedu Eze']], $this->idem($this->token))->assertStatus(201)->json();
        $h = fn (array $b) => $this->idem($this->token, ['If-Match' => '"v'.$b['rowVersion'].'"']);

        // wrong amount: Payments refuses, NOTHING changes (no order, no booking change)
        $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '4000.0000']]], $h($held))->assertStatus(422);
        $this->assertSame('HELD', DB::table('booking')->value('status'));
        $this->assertSame(0, DB::table('payment')->count());

        $ok = $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '5000.0000', 'tendered' => '10000.0000']]], $h($held))->assertOk()
            ->assertJsonPath('status', 'CONFIRMED')->assertJsonPath('amountPaid', '5000.0000')->json();
        $this->assertNotNull($ok['orderId']);
        $this->assertNotNull($ok['entitlementId']);
        $this->assertSame('5000.0000', DB::table('order')->where('id', Ids::toBinary($ok['orderId']))->value('amount_paid'));
        $this->assertSame(1, DB::table('payment')->where('status', 'CAPTURED')->count());
        $this->assertSame(1, DB::table('entitlement')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'BookingConfirmedLocally')->count());
        $this->assertTrue(Audit::verifyChain()->valid);

        // a second confirm of the same booking cannot pay again
        $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '5000.0000']]], $this->idem($this->token, ['If-Match' => '"v'.$ok['rowVersion'].'"']))->assertStatus(409);
        $this->assertSame(1, DB::table('payment')->count());
    }
}
