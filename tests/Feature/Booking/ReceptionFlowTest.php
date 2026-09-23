<?php

namespace Tests\Feature\Booking;

use App\Domain\Orders\Events\OrderSettled;
use App\Domain\Orders\Services\OrderSettlementService;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BookingHelpers;
use Tests\Support\OrdersFixture;
use Tests\Support\TestData;
use Tests\TestCase;

/**
 * Reception flow (architecture/10 §4, workflows/sports.md): hold slot -> order lines (slot fee + rental + store items)
 * -> payment -> confirm the booking + issue ONE QR entitlement, in the payment's transaction.
 * Payments' `PaymentCaptured` is simulated by dispatching the same duck-typed payload (orderIds, subjectType/Id).
 */
class ReceptionFlowTest extends TestCase
{
    use BookingHelpers;

    private array $w;

    private string $token;

    /** @var array<string, string> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        $org = Ids::toBinary($this->w['t']['org']);
        $cat = OrdersFixture::insert('product_category', ['organization_id' => $org, 'name' => 'Sports']);
        $list = OrdersFixture::insert('price_list', ['organization_id' => $org, 'name' => 'Standard', 'is_default' => 1]);
        foreach ([['fee', 'COURT-FEE', 'Court booking fee', 'FEE', '5000', false], ['racket', 'RACKET', 'Racket hire', 'RENTAL', '1500', false], ['balls', 'BALLS', 'Tennis balls', 'GOOD', '2500', true],
            ['cola', 'COLA', 'Cola', 'GOOD', '500', false], ['adult', 'POOL-ADULT', 'Pool - Adult', 'TICKET', '3000', false], ['child', 'POOL-CHILD', 'Pool - Child', 'TICKET', '1500', false]] as [$k, $sku, $name, $kind, $price, $atStore]) {
            $id = OrdersFixture::insert('product', ['organization_id' => $org, 'category_id' => Ids::toBinary($cat), 'sku' => $sku, 'name' => $name, 'kind' => $kind]);
            DB::table('product_facility')->insert(['product_id' => Ids::toBinary($id), 'facility_unit_id' => Ids::toBinary($this->w['reception']->id)]);
            if ($atStore) {
                DB::table('product_facility')->insert(['product_id' => Ids::toBinary($id), 'facility_unit_id' => Ids::toBinary($this->w['store']->id)]);
            }
            OrdersFixture::insert('price', ['price_list_id' => Ids::toBinary($list), 'product_id' => Ids::toBinary($id), 'amount' => $price]);
            $this->p[$k] = $id;
        }
        foreach ([['POOL-ADULT', 'adult'], ['POOL-CHILD', 'child']] as [$code, $k]) {
            DB::table('ticket_type')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $org, 'site_id' => Ids::toBinary($this->w['t']['site']), 'facility_unit_id' => Ids::toBinary($this->w['pool']->id),
                'product_id' => Ids::toBinary($this->p[$k]), 'code' => $code, 'name' => $code, 'format' => 'INDIVIDUAL', 'validation_mode' => 'SINGLE_USE']);
        }
        DB::table('facility_unit')->where('id', Ids::toBinary($this->w['store']->id))->update(['code' => 'SPORTS-STORE']);
        [$staff, $this->token] = $this->staffWith($this->w, 'rita', self::BOOKING_PERMS);
        TestData::assign($staff, 'CASHIER', 'FACILITY_UNIT', $this->w['reception']->id);
    }

    private function order(array $lines)
    {
        return $this->postJson('/api/v1/orders', ['facilityId' => $this->w['reception']->id, 'channel' => 'COUNTER', 'customerName' => 'Chinedu Eze',
            'lines' => array_map(fn ($l) => ['productId' => $this->p[$l[0]], 'quantity' => $l[1]], $lines)], $this->idem($this->token))->assertStatus(201);
    }

    private function hold(?array $slot = null)
    {
        $r = $this->resource($this->w, ['product_id' => $this->p['fee']]);
        $slot ??= $this->slot();

        return [$r, $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1], 'customer' => ['name' => 'Chinedu Eze']], $this->idem($this->token))->assertStatus(201)->json()];
    }

    /** Simulate Payments: allocate the money to the order, then fire PaymentCaptured (inside the same test transaction). */
    private function pay(string $orderId, ?string $amount = null): void
    {
        $order = DB::table('order')->where('id', Ids::toBinary($orderId))->first();
        app(OrderSettlementService::class)->applyPayment($orderId, $amount ?? $order->total);
        $this->capture([$orderId]);
    }

    private function capture(array $orderIds, ?string $type = null, ?string $subjectId = null, string $amount = '0.0000'): void
    {
        Event::dispatch('App\Domain\Payments\Events\PaymentCaptured', [(object) [
            'paymentId' => Ids::uuid7(), 'groupId' => Ids::uuid7(), 'orderIds' => $orderIds, 'amount' => $amount, 'tenders' => [], 'facilityId' => $this->w['reception']->id,
            'subjectType' => $type, 'subjectId' => $subjectId, 'provider' => 'MANUAL',
        ]]);
    }

    public function test_reception_flow_confirms_the_booking_and_issues_one_qr_with_court_rental_and_store_items(): void
    {
        [$r, $held] = $this->hold();
        $order = $this->order([['fee', 1], ['racket', 2], ['balls', 1], ['cola', 1], ['adult', 2], ['child', 1]])->json();
        $this->assertSame('5000.0000', $held['total']);

        $attached = $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => $order['id']], $this->idem($this->token, ['If-Match' => '"v'.$held['rowVersion'].'"']))
            ->assertOk()->assertJsonPath('status', 'PENDING_PAYMENT')->assertJsonPath('orderId', $order['id'])->json();
        $this->assertNotSame($held['holdExpiresAt'], null);

        // partly paid: nothing happens
        $this->pay($order['id'], '1000.0000');
        $this->assertSame('PENDING_PAYMENT', DB::table('booking')->value('status'));
        $this->assertSame(0, DB::table('entitlement')->count());

        // paid in full: booking CONFIRMED + entitlements, atomically with the payment
        $this->pay($order['id'], bcsub($order['total'], '1000.0000', 4));
        $b = $this->getJson("/api/v1/bookings/{$held['id']}", $this->idem($this->token))->assertOk()->assertJsonPath('status', 'CONFIRMED')->assertJsonPath('amountPaid', '5000.0000')->json();
        $this->assertNotNull($b['entitlementId']);
        $ent = $this->getJson("/api/v1/entitlements/{$b['entitlementId']}", $this->idem($this->token))->assertOk()->json();
        $items = collect($ent['items']);
        $this->assertSame(['ACCESS', 'RENTAL', 'ITEM'], $items->pluck('kind')->all());
        $this->assertSame([1, 2, 1], $items->pluck('quantity')->all());
        $this->assertSame([$this->w['arena']->id, $this->w['store']->id, $this->w['store']->id], $items->pluck('facilityId')->all());
        $this->assertSame('Tennis balls', $items[2]['name']);
        $this->assertNotContains('Cola', $items->pluck('name')->all());
        $this->assertSame('NOT_RELEASED', $items[1]['rentalStatus']);

        // 2 adults + 1 child pool tickets: INDIVIDUAL -> 3 entitlements; rentals did NOT get a separate entitlement (they ride on the booking QR)
        $this->assertSame(3, DB::table('entitlement')->where('order_id', Ids::toBinary($order['id']))->count());
        $this->assertSame(1, DB::table('entitlement')->count() - 3);
        $this->assertSame(0, DB::table('entitlement_item')->where('kind', 'RENTAL')->where('entitlement_id', '!=', Ids::toBinary($b['entitlementId']))->count());

        // replaying the settlement events changes nothing (idempotent listeners)
        $this->capture([$order['id']]);
        Event::dispatch('App\Domain\Orders\Events\OrderSettled', [new OrderSettled($order['id'], $this->w['reception']->id, $order['total'], $order['total'])]);
        $this->assertSame(4, DB::table('entitlement')->count());
        $this->assertSame(1, DB::table('slot_allocation')->where('status', 'CONFIRMED')->count());
        $this->assertTrue(DB::table('outbox_event')->where('event_type', 'BookingConfirmedLocally')->count() === 1);
        $this->assertTrue(Audit::verifyChain()->valid);

        // Sports Store: scan the same QR, release the racket, take it back
        [, $store] = $this->staffWith($this->w, 'storeguy', self::SCAN_PERMS);
        $look = $this->getJson('/api/v1/entitlement-tokens/'.$ent['qrToken'], $this->idem($store))->assertOk()->json();
        $this->postJson("/api/v1/entitlements/{$ent['id']}/release", ['itemIds' => [$look['items'][1]['id']]], $this->idem($store))->assertOk()->assertJsonPath('items.1.rentalStatus', 'RELEASED');
        $this->postJson("/api/v1/entitlements/{$ent['id']}/return", ['itemIds' => [$look['items'][1]['id']], 'condition' => 'OK'], $this->idem($store))->assertOk()->assertJsonPath('items.1.rentalStatus', 'RETURNED');
    }

    public function test_attach_order_validates_amount_state_and_uniqueness(): void
    {
        [, $held] = $this->hold();
        $noFee = $this->order([['cola', 1]])->json();
        $h = fn (array $b) => $this->idem($this->token, ['If-Match' => '"v'.$b['rowVersion'].'"']);
        $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => $noFee['id']], $h($held))->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => Ids::uuid7()], $h($held))->assertStatus(404);
        $twoFees = $this->order([['fee', 2]])->json();
        $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => $twoFees['id']], $h($held))->assertStatus(422); // 10000 != 5000

        $good = $this->order([['fee', 1]])->json();
        $ok = $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => $good['id']], $h($held))->assertOk()->json();
        // the same order cannot pay for a second booking
        $r2 = $this->resource($this->w, ['product_id' => $this->p['fee']]);
        $slot = $this->slot(14);
        $held2 = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r2->id, 'start' => $slot[0], 'end' => $slot[1]], $this->idem($this->token))->json();
        $this->postJson("/api/v1/bookings/{$held2['id']}/order", ['orderId' => $good['id']], $h($held2))->assertStatus(409)->assertJsonPath('code', 'booking_state_invalid');
        // stale If-Match
        $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => $good['id']], $h($held))->assertStatus(412);
        // an expired hold cannot take an order
        DB::table('booking')->where('id', Ids::toBinary($held2['id']))->update(['hold_expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $fresh = $this->order([['fee', 1]])->json();
        $this->postJson("/api/v1/bookings/{$held2['id']}/order", ['orderId' => $fresh['id']], $h($held2))->assertStatus(409)->assertJsonPath('code', 'hold_expired');
        $this->assertSame('PENDING_PAYMENT', $ok['status']);
    }

    public function test_payment_for_an_expired_hold_fails_and_leaves_nothing_behind(): void
    {
        [, $held] = $this->hold();
        $order = $this->order([['fee', 1]])->json();
        $this->postJson("/api/v1/bookings/{$held['id']}/order", ['orderId' => $order['id']], $this->idem($this->token, ['If-Match' => '"v'.$held['rowVersion'].'"']))->assertOk();
        DB::table('booking')->where('id', Ids::toBinary($held['id']))->update(['hold_expires_at' => now('UTC')->subSecond()->format('Y-m-d H:i:s.u')]);
        try {
            $this->pay($order['id']);
            $this->fail('paying for an expired hold must fail');
        } catch (ApiProblem $e) {
            $this->assertSame('hold_expired', $e->problemCode);
        }
    }

    public function test_online_payment_of_the_booking_itself_confirms_it(): void
    {
        [, $held] = $this->hold();
        $this->capture([], 'BOOKING', $held['id'], '5000.0000');
        $this->assertSame('CONFIRMED', DB::table('booking')->where('id', Ids::toBinary($held['id']))->value('status'));
        $this->assertSame(1, DB::table('entitlement')->count());
        $this->capture([], 'BOOKING', $held['id'], '5000.0000'); // duplicate webhook: no-op
        $this->assertSame(1, DB::table('entitlement')->count());
        // underpayment is refused
        $r = $this->resource($this->w);
        $slot = $this->slot(15);
        $h2 = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1]], $this->idem($this->token))->json();
        try {
            $this->capture([], 'BOOKING', $h2['id'], '100.0000');
            $this->fail('underpayment must not confirm');
        } catch (ApiProblem $e) {
            $this->assertSame('amount_mismatch', $e->problemCode);
        }
    }

    public function test_paid_ticket_orders_issue_individual_tickets_and_unpaid_ones_do_not(): void
    {
        $order = $this->order([['child', 3], ['adult', 2], ['racket', 1]])->json();
        $this->capture([$order['id']]); // captured event but nothing applied yet: order not fully paid
        $this->assertSame(0, DB::table('entitlement')->count());
        $this->postJson('/api/v1/entitlements', ['orderId' => $order['id']], $this->idem($this->token))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');

        $this->pay($order['id']);
        $tickets = DB::table('entitlement')->where('order_id', Ids::toBinary($order['id']))->count();
        $this->assertSame(6, $tickets); // 3 children + 2 adults + 1 rentals entitlement
        $this->assertSame(5, DB::table('entitlement_item')->where('kind', 'ACCESS')->where('qty', 1)->count());
        $this->assertSame(5, DB::table('entitlement_item')->where('kind', 'ACCESS')->where('facility_unit_id', Ids::toBinary($this->w['pool']->id))->count());

        // manual issue is idempotent and returns the group
        $again = $this->postJson('/api/v1/entitlements', ['orderId' => $order['id']], $this->idem($this->token))->assertStatus(201)->json();
        $this->assertCount(6, $again['groupEntitlementIds']);
        $this->assertSame(6, DB::table('entitlement')->count());
        $this->assertCount(6, $this->getJson('/api/v1/entitlements?filter[orderId]='.$order['id'], $this->idem($this->token))->json('items'));

        // a pool ticket works at the pool gate
        [, $gate] = $this->staffWith($this->w, 'poolgate', self::SCAN_PERMS);
        $tok = DB::table('entitlement_item as i')->join('entitlement as e', 'e.id', '=', 'i.entitlement_id')->where('i.kind', 'ACCESS')->value('e.qr_token');
        $this->postJson("/api/v1/entitlement-tokens/{$tok}/redeem", ['action' => 'ENTRY', 'facilityId' => $this->w['pool']->id], $this->idem($gate))->assertJsonPath('result', 'VALID');
    }
}
