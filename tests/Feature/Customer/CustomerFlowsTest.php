<?php

namespace Tests\Feature\Customer;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CustomerHelpers;
use Tests\Support\DemoApi;
use Tests\Support\PaystackFakes;
use Tests\TestCase;

/** The website's journeys against the real engine: hold -> Paystack -> confirm -> QR, pool tickets, memberships, and strict ownership. */
class CustomerFlowsTest extends TestCase
{
    use CustomerHelpers, DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PaystackFakes::configure();
        PaystackFakes::http();
    }

    /** @return array{0: string, 1: string} */
    private function slot(int $hour = 10, int $daysAhead = 3): array
    {
        $s = CarbonImmutable::now('Africa/Lagos')->addDays($daysAhead)->setTime($hour, 0)->utc();

        return [$s->format('Y-m-d\TH:i:s\Z'), $s->addHour()->format('Y-m-d\TH:i:s\Z')];
    }

    private function hold(array $c, string $resourceId, array $slot): TestResponse
    {
        return $this->postJson('/api/v1/bookings/hold', ['resourceId' => $resourceId, 'start' => $slot[0], 'end' => $slot[1], 'customer' => ['name' => 'Forged Name', 'email' => 'forged@example.test']], $this->bearer($c['accessToken']));
    }

    private function pay(array $c, array $subject, string $amount): array
    {
        $init = $this->postJson('/api/v1/payments/paystack/initialize', $subject + ['amount' => $amount, 'email' => 'ignored@example.test', 'callbackUrl' => 'http://127.0.0.1:8092/payment/return'], $this->bearer($c['accessToken']))->assertStatus(201)->json();
        PaystackFakes::remember($init['reference'], $amount);

        return $init;
    }

    public function test_customer_books_a_tennis_court_pays_with_paystack_and_gets_a_qr(): void
    {
        $a = $this->newCustomer();
        $res = Ids::fromBinary($this->resourceByName('Lawn Tennis Court 1')->id);
        $slot = $this->slot();

        $h = $this->hold($a, $res, $slot)->assertStatus(201);
        $b = $h->json();
        $this->assertSame('HELD', $b['status']);
        $this->assertSame($a['customer']['id'], $b['customerId']);
        $this->assertSame('ONLINE', $b['source']);
        $this->assertSame('Ada Guest', $b['customer']['name'], 'identity comes from the account, not the request body');
        $this->assertSame($a['email'], $b['customer']['email']);
        $this->assertTrue($b['policy']['canCancel']);

        $init = $this->pay($a, ['bookingId' => $b['id']], $b['total']);
        $this->assertStringStartsWith('R007-', $init['reference']);
        $this->assertSame($a['email'], DB::table('payment')->where('provider_reference', $init['reference'])->value('customer_email'), 'Paystack email is the account email');
        $p = $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->bearer($a['accessToken'], null))->assertOk();
        $this->assertSame('CAPTURED', $p->json('status'));

        $bk = $this->getJson('/api/v1/bookings/'.$b['id'], $this->bearer($a['accessToken'], null))->assertOk()->json();
        $this->assertSame('CONFIRMED', $bk['status']);
        $this->assertNotNull($bk['entitlementId']);
        $this->assertSame($b['total'], $bk['amountPaid']);
        $this->assertSame($b['total'], $bk['policy']['refundAmount']);
        $this->assertTrue($bk['policy']['canReschedule']);

        $ents = $this->getJson('/api/v1/customer/entitlements?bookingId='.$b['id'], $this->bearer($a['accessToken'], null))->assertOk()->json('items');
        $this->assertCount(1, $ents);
        $this->assertStringStartsWith('R7.', $ents[0]['qrToken']);
        $this->getJson('/api/v1/entitlements/'.$bk['entitlementId'], $this->bearer($a['accessToken'], null))->assertOk();
        $this->assertSame([$b['id']], array_column($this->getJson('/api/v1/customer/bookings', $this->bearer($a['accessToken'], null))->json('items'), 'id'));
        $this->assertGreaterThan(0, DB::table('outbox_event')->where('event_type', 'BookingConfirmedLocally')->count());

        // the same slot is gone for everyone (staff too)
        $other = $this->newCustomer();
        $this->hold($other, $res, $slot)->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => $res, 'start' => $slot[0], 'end' => $slot[1], 'customer' => ['name' => 'Walk in']], $this->bearer($this->loginAs('cashier1')['accessToken']))->assertStatus(409);

        // reschedule (If-Match with the quoted row version, as the website sends it) then cancel
        $s2 = $this->slot(12);
        $r = $this->postJson("/api/v1/bookings/{$b['id']}/reschedule", ['start' => $s2[0], 'end' => $s2[1]], $this->bearer($a['accessToken']) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertOk()->json();
        $this->assertSame($s2[0], $r['start']);
        $c = $this->postJson("/api/v1/bookings/{$b['id']}/cancel", ['reason' => 'changed my mind'], $this->bearer($a['accessToken']) + ['If-Match' => '"'.$r['rowVersion'].'"'])->assertOk()->json();
        $this->assertSame('CANCELLED', $c['status']);
        $this->assertFalse($c['policy']['canCancel']);
    }

    public function test_customer_cannot_confirm_with_tenders_or_touch_other_customers_bookings_payments_or_tickets(): void
    {
        $a = $this->newCustomer();
        $bob = $this->newCustomer();
        $res = Ids::fromBinary($this->resourceByName('Lawn Tennis Court 2')->id);
        $bk = $this->hold($a, $res, $this->slot(9))->assertStatus(201)->json();

        $this->postJson("/api/v1/bookings/{$bk['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => $bk['total']]]], $this->bearer($a['accessToken']) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertStatus(403);

        // Bob: every door is a 404 (indistinguishable from a missing id)
        $bt = $bob['accessToken'];
        $this->getJson('/api/v1/bookings/'.$bk['id'], $this->bearer($bt, null))->assertStatus(404);
        $this->postJson("/api/v1/bookings/{$bk['id']}/cancel", ['reason' => 'x'], $this->bearer($bt) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertStatus(404);
        $s = $this->slot(13);
        $this->postJson("/api/v1/bookings/{$bk['id']}/reschedule", ['start' => $s[0], 'end' => $s[1]], $this->bearer($bt) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertStatus(404);
        $this->postJson("/api/v1/bookings/{$bk['id']}/confirm", [], $this->bearer($bt) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertStatus(404);
        $this->postJson('/api/v1/payments/paystack/initialize', ['bookingId' => $bk['id'], 'amount' => $bk['total'], 'email' => 'x@example.test'], $this->bearer($bt))->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/v1/customer/bookings', $this->bearer($bt, null))->json('items'));
        $this->assertSame(404, $this->getJson('/api/v1/bookings/'.Ids::uuid7(), $this->bearer($bt, null))->status());

        // pay as Alice, then Bob cannot read the payment, entitlement or order data
        $init = $this->pay($a, ['bookingId' => $bk['id']], $bk['total']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->bearer($bt, null))->assertStatus(404);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->bearer($a['accessToken'], null))->assertOk();
        $ent = $this->getJson('/api/v1/bookings/'.$bk['id'], $this->bearer($a['accessToken'], null))->json('entitlementId');
        $this->getJson('/api/v1/entitlements/'.$ent, $this->bearer($bt, null))->assertStatus(404);
        $this->getJson('/api/v1/customer/entitlements?bookingId='.$bk['id'], $this->bearer($bt, null))->assertOk()->assertJsonPath('items', []);
        $this->getJson('/api/v1/customer/orders/'.Ids::uuid7(), $this->bearer($bt, null))->assertStatus(404);
        // the service token cannot read anyone's booking or ticket either
        $this->getJson('/api/v1/bookings/'.$bk['id'], $this->bearer($this->serviceToken(), null))->assertStatus(404);
        // and staff endpoints keep working for staff
        $this->getJson('/api/v1/bookings/'.$bk['id'], $this->bearer($this->loginAs('cashier1')['accessToken'], null))->assertOk();
    }

    public function test_pool_ticket_order_is_priced_by_the_server_paid_and_issues_one_qr_per_person_for_the_visit_day(): void
    {
        $a = $this->newCustomer();
        $bob = $this->newCustomer();
        $pool = DemoIds::facility('POOL_AREA');
        $products = collect($this->getJson("/api/v1/catalog/products?facilityId={$pool}&filter[kind]=TICKET", $this->bearer($this->serviceToken(), null))->assertOk()->json('items'));
        $this->assertNotEmpty($products);
        $this->assertArrayNotHasKey('prepRoute', $products[0]);
        $adult = $products->firstWhere('ticketCategory', 'ADULT');
        $child = $products->firstWhere('ticketCategory', 'CHILD');
        $this->assertNotNull($child);
        $visit = CarbonImmutable::now('Africa/Lagos')->addDays(2)->format('Y-m-d');
        $body = ['facilityId' => $pool, 'visitDate' => $visit, 'lines' => [['productId' => $adult['id'], 'quantity' => 2], ['productId' => $child['id'], 'quantity' => 3]], 'customer' => ['name' => 'ignored']];
        $key = 'order-'.bin2hex(random_bytes(6));

        $o = $this->postJson('/api/v1/public/ticket-orders', $body, $this->bearer($a['accessToken'], $key))->assertStatus(201)->json();
        $expected = bcadd(bcmul($adult['price'], '2', 4), bcmul($child['price'], '3', 4), 4);
        $this->assertSame($expected, $o['total']);
        $this->assertSame(2, $o['adultCount']);
        $this->assertSame(3, $o['childCount']);
        $this->assertFalse($o['paid']);
        $replay = $this->postJson('/api/v1/public/ticket-orders', $body, $this->bearer($a['accessToken'], $key))->assertStatus(201);
        $this->assertSame($o['id'], $replay->json('id'));
        $this->assertSame('true', $replay->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, DB::table('customer_order')->where('customer_id', Ids::toBinary($a['customer']['id']))->count());
        $tampered = $body;
        $tampered['lines'][0]['quantity'] = 9;
        $this->postJson('/api/v1/public/ticket-orders', $tampered, $this->bearer($a['accessToken'], $key))->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->postJson('/api/v1/public/ticket-orders', ['lines' => [['productId' => $adult['id'], 'quantity' => 99]]] + $body, $this->bearer($a['accessToken']))->assertStatus(422);
        $this->postJson('/api/v1/public/ticket-orders', $body, ['Accept' => 'application/json', 'Idempotency-Key' => 'z'])->assertStatus(401);
        $this->postJson('/api/v1/public/ticket-orders', $body, $this->bearer($this->serviceToken()))->assertStatus(401);

        // Bob cannot see or pay Alice's order
        $this->getJson('/api/v1/customer/orders/'.$o['id'], $this->bearer($bob['accessToken'], null))->assertStatus(404);
        $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$o['id']], 'amount' => $o['total'], 'email' => 'x@example.test'], $this->bearer($bob['accessToken']))->assertStatus(404);
        // a wrong amount is refused; the right one works
        $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$o['id']], 'amount' => '1.0000', 'email' => 'x@example.test'], $this->bearer($a['accessToken']))->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $init = $this->pay($a, ['orderIds' => [$o['id']]], $o['total']);
        $this->getJson('/api/v1/customer/entitlements?orderId='.$o['id'], $this->bearer($a['accessToken'], null))->assertOk()->assertJsonPath('items', []); // nothing before payment
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->bearer($a['accessToken'], null))->assertOk()->assertJsonPath('status', 'CAPTURED');

        $order = $this->getJson('/api/v1/customer/orders/'.$o['id'], $this->bearer($a['accessToken'], null))->assertOk()->json();
        $this->assertTrue($order['paid']);
        $this->assertCount(5, $order['entitlementIds']);
        $ents = $this->getJson('/api/v1/customer/entitlements?orderId='.$o['id'], $this->bearer($a['accessToken'], null))->assertOk()->json('items');
        $this->assertCount(5, $ents);
        $this->assertCount(5, array_unique(array_column($ents, 'qrToken')));
        $from = CarbonImmutable::parse($ents[0]['items'][0]['validFrom'])->setTimezone('Africa/Lagos');
        $this->assertSame($visit, $from->format('Y-m-d'), 'valid on the chosen visit day');
        $this->assertSame($a['customer']['id'], DB::table('entitlement')->where('order_id', Ids::toBinary($o['id']))->value('customer_id') ? Ids::fromBinary(DB::table('entitlement')->where('order_id', Ids::toBinary($o['id']))->value('customer_id')) : null);
        $this->assertSame([], $this->getJson('/api/v1/customer/entitlements?orderId='.$o['id'], $this->bearer($bob['accessToken'], null))->json('items'));
        // a second verify (or the webhook) is idempotent: still 5 tickets
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->bearer($a['accessToken'], null))->assertOk();
        $this->assertSame(5, DB::table('entitlement')->where('order_id', Ids::toBinary($o['id']))->count());
    }

    public function test_same_day_tickets_are_refused_when_the_local_node_is_stale_on_the_cloud_node(): void
    {
        config(['node.node' => 'cloud']);
        $a = $this->newCustomer();
        $pool = DemoIds::facility('POOL_AREA');
        $p = collect($this->getJson("/api/v1/catalog/products?facilityId={$pool}", $this->bearer($this->serviceToken(), null))->json('items'))->first();
        $line = [['productId' => $p['id'], 'quantity' => 1]];
        $today = CarbonImmutable::now('Africa/Lagos')->format('Y-m-d');
        $this->postJson('/api/v1/public/ticket-orders', ['facilityId' => $pool, 'visitDate' => $today, 'lines' => $line], $this->bearer($a['accessToken']))->assertStatus(409)->assertJsonPath('code', 'site_offline');
        $this->assertSame(0, DB::table('customer_order')->count());
        $site = $this->getJson('/api/v1/public/site')->json();
        $this->assertFalse($site['siteAvailability']['localNodeFresh']);
        $poolF = collect($site['facilities'])->firstWhere('code', 'POOL_AREA');
        $this->assertFalse($poolF['sameDayAvailable']);
        $this->assertNotNull($poolF['onlineNotice']);
        $this->assertTrue($poolF['onlineBookable'], 'the rest of the site stays available');
        $this->postJson('/api/v1/public/ticket-orders', ['facilityId' => $pool, 'visitDate' => CarbonImmutable::now('Africa/Lagos')->addDays(2)->format('Y-m-d'), 'lines' => $line], $this->bearer($a['accessToken']))->assertStatus(201);
    }

    public function test_online_membership_purchase_is_owned_paid_via_paystack_and_activated(): void
    {
        $a = $this->newCustomer();
        $bob = $this->newCustomer();
        $plan = collect($this->getJson('/api/v1/memberships/plans', $this->bearer($this->serviceToken(), null))->assertOk()->json('items'))->first();
        $m = $this->postJson('/api/v1/memberships', ['planId' => $plan['id'], 'customer' => ['name' => 'Someone Else', 'phone' => '+2348000000001'], 'tenders' => [['tenderType' => 'CASH', 'amount' => '1.0000']]], $this->bearer($a['accessToken']))->assertStatus(201)->json();
        $this->assertSame('PENDING_PAYMENT', $m['status']);
        $this->assertSame($a['customer']['id'], $m['customerId']);
        $this->postJson('/api/v1/memberships', ['planId' => $plan['id'], 'customer' => ['name' => 'x']], $this->bearer($this->serviceToken()))->assertStatus(403);

        $this->getJson('/api/v1/memberships/'.$m['id'], $this->bearer($bob['accessToken'], null))->assertStatus(404);
        $this->postJson('/api/v1/payments/paystack/initialize', ['membershipId' => $m['id'], 'amount' => $plan['price'], 'email' => 'x@example.test'], $this->bearer($bob['accessToken']))->assertStatus(404);
        $init = $this->pay($a, ['membershipId' => $m['id']], $plan['price']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->bearer($a['accessToken'], null))->assertOk()->assertJsonPath('status', 'CAPTURED');
        $mine = $this->getJson('/api/v1/customer/memberships', $this->bearer($a['accessToken'], null))->assertOk()->json('items');
        $this->assertCount(1, $mine);
        $this->assertSame('ACTIVE', $mine[0]['status']);
        $this->assertSame([], $this->getJson('/api/v1/customer/memberships', $this->bearer($bob['accessToken'], null))->json('items'));
    }

    public function test_public_resources_hide_authority_config_and_offline_resources(): void
    {
        DB::table('bookable_resource')->where('name', 'Lawn Tennis Court 2')->update(['online_bookable' => 0]);
        $items = $this->getJson('/api/v1/bookings/resources', $this->bearer($this->serviceToken(), null))->assertOk()->json('items');
        $this->assertNotContains('Lawn Tennis Court 2', array_column($items, 'name'));
        $this->assertNull($items[0]['authority']);
        $staff = $this->getJson('/api/v1/bookings/resources', $this->bearer($this->loginAs('manager1')['accessToken'], null))->json('items');
        $this->assertContains('Lawn Tennis Court 2', array_column($staff, 'name'));
        $this->assertNotNull($staff[0]['authority']);
        $a = $this->newCustomer();
        $r2 = Ids::fromBinary($this->resourceByName('Lawn Tennis Court 2')->id);
        $this->getJson("/api/v1/bookings/resources/{$r2}/availability?from=".urlencode(now()->addDay()->toIso8601String()).'&to='.urlencode(now()->addDays(2)->toIso8601String()), $this->bearer($this->serviceToken(), null))->assertStatus(404);
        $this->hold($a, $r2, $this->slot())->assertStatus(409)->assertJsonPath('code', 'capability_disabled');
    }
}
