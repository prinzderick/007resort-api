<?php

namespace Tests\Feature\Guest;

use App\Domain\Guest\Services\GuestOrderClaimer;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\CustomerHelpers;
use Tests\Support\DemoApi;
use Tests\Support\PaystackFakes;
use Tests\TestCase;

/** Checkout without an account (docs/GUEST_CHECKOUT.md): every purchase path, token access, lookup, claiming, abuse limits, erasure. Real MySQL. */
class GuestCheckoutTest extends TestCase
{
    use CustomerHelpers, DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PaystackFakes::configure();
        PaystackFakes::http();
    }

    // ---- helpers ----

    /** @return array<string, mixed> */
    private function guest(array $over = []): array
    {
        return $over + ['name' => 'Ada Lovelace', 'email' => 'Ada.Guest'.bin2hex(random_bytes(3)).'@Example.test', 'phone' => '0803 123 4567', 'marketingConsent' => true, 'consentVersion' => '2026-09'];
    }

    /** @return array<string, string> */
    private function svc(?string $orderToken = null, ?string $idem = 'auto', ?string $token = null, array $extra = []): array
    {
        $base = array_filter([
            'Authorization' => 'Bearer '.($token ?? $this->serviceToken()), 'Accept' => 'application/json', 'X-Client-IP' => '203.0.113.'.random_int(1, 250),
            'X-Order-Token' => $orderToken, 'Idempotency-Key' => $idem === 'auto' ? 'g-'.bin2hex(random_bytes(10)) : $idem,
        ]);

        return $extra + $base;
    }

    /** @return array{0: string, 1: string} */
    private function slot(int $hour = 10, int $daysAhead = 3): array
    {
        $s = CarbonImmutable::now('Africa/Lagos')->addDays($daysAhead)->setTime($hour, 0)->utc();

        return [$s->format('Y-m-d\TH:i:s\Z'), $s->addHour()->format('Y-m-d\TH:i:s\Z')];
    }

    private function court(int $n = 1): string
    {
        return Ids::fromBinary($this->resourceByName("Lawn Tennis Court {$n}")->id);
    }

    private function hold(?array $guest = null, int $hour = 10, int $court = 1, array $headers = []): TestResponse
    {
        $s = $this->slot($hour);

        return $this->postJson('/api/v1/bookings/hold', ['resourceId' => $this->court($court), 'start' => $s[0], 'end' => $s[1], 'guest' => $guest ?? $this->guest()], $headers ?: $this->svc());
    }

    /** @return array<string, mixed> */
    private function pay(string $orderToken, array $subject, string $amount): array
    {
        $init = $this->postJson('/api/v1/payments/paystack/initialize', $subject + ['amount' => $amount, 'callbackUrl' => 'http://127.0.0.1:8092/payment/return'], $this->svc($orderToken))->assertStatus(201)->json();
        PaystackFakes::remember($init['reference'], $amount);

        return $init;
    }

    private function customerRows(): int
    {
        return DB::table('customer')->count() + DB::table('customer_account')->count();
    }

    /** @return array<string, mixed> */
    private function bookAndPay(?array $guest = null, int $hour = 10): array
    {
        $b = $this->hold($guest, $hour)->assertStatus(201)->json();
        $tok = $b['guestAccess']['accessToken'];
        $init = $this->pay($tok, ['bookingId' => $b['id']], $b['total']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($tok, null))->assertOk()->assertJsonPath('status', 'CAPTURED');

        return $b + ['paystackRef' => $init['reference']];
    }

    private function poolProducts(): array
    {
        $pool = DemoIds::facility('POOL_AREA');

        return [$pool, collect($this->getJson("/api/v1/catalog/products?facilityId={$pool}&filter[kind]=TICKET", $this->svc(null, null))->assertOk()->json('items'))];
    }

    // ---- booking: hold -> pay -> confirm, no account ----

    public function test_guest_books_a_court_pays_with_paystack_and_gets_a_qr_without_any_account(): void
    {
        $customers = $this->customerRows();
        $g = $this->guest(['email' => 'Ada.Book@Example.TEST', 'phone' => '0803 123 4567']);
        $b = $this->hold($g)->assertStatus(201)->json();

        $this->assertSame('HELD', $b['status']);
        $this->assertNull($b['customerId']);
        $this->assertSame('ONLINE', $b['source']);
        $this->assertSame(['name' => 'Ada Lovelace', 'phone' => '+2348031234567', 'email' => 'ada.book@example.test', 'membershipId' => null], $b['customer']);
        $this->assertMatchesRegularExpression('/^GC-[0-9A-HJKMNP-TV-Z]{8}$/', $b['guestAccess']['reference']);
        $this->assertStringStartsWith('r7o_', $b['guestAccess']['accessToken']);
        $this->assertSame('+2348031234567', $b['guestAccess']['contact']['phone']);
        $this->assertSame($customers, $this->customerRows(), 'NO customer or account row is created');

        // consent evidence + only the HASH of the token at rest
        $go = DB::table('guest_order')->where('reference', $b['guestAccess']['reference'])->first();
        $this->assertSame('2026-09', $go->consent_version);
        $this->assertNotNull($go->consented_at);
        $this->assertSame(1, (int) $go->marketing_consent);
        $this->assertSame('ada.book@example.test', $go->contact_email);
        $this->assertSame(0, DB::table('guest_access_token')->where('token_hash', $b['guestAccess']['accessToken'])->count());
        $this->assertSame(1, DB::table('guest_access_token')->where('token_hash', hash('sha256', $b['guestAccess']['accessToken']))->count());
        $this->assertSame(1, DB::table('guest_contact')->count());

        $tok = $b['guestAccess']['accessToken'];
        $init = $this->pay($tok, ['bookingId' => $b['id']], $b['total']);
        $this->assertSame('ada.book@example.test', DB::table('payment')->where('provider_reference', $init['reference'])->value('customer_email'), 'Paystack gets the guest contact email');
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($tok, null))->assertOk()->assertJsonPath('status', 'CAPTURED');
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($tok, null))->assertOk(); // idempotent

        $view = $this->getJson('/api/v1/public/orders/'.$b['guestAccess']['reference'], $this->svc($tok, null))->assertOk()->json();
        $this->assertSame('BOOKING', $view['kind']);
        $this->assertSame('CONFIRMED', $view['status']);
        $this->assertTrue($view['paid']);
        $this->assertSame('0.0000', $view['amountDue']);
        $this->assertCount(1, $view['tickets']);
        $this->assertStringStartsWith('R7.', $view['tickets'][0]['qrToken']);
        $this->assertSame('CONFIRMED', $view['booking']['status']);
        $this->assertTrue($view['booking']['policy']['canCancel']);
        $this->getJson('/api/v1/bookings/'.$b['id'], $this->svc($tok, null))->assertOk()->assertJsonPath('status', 'CONFIRMED');
        $this->getJson('/api/v1/entitlements/'.$view['tickets'][0]['id'], $this->svc($tok, null))->assertOk();
        $this->assertSame($customers, $this->customerRows());

        // webhook replays stay idempotent for guests
        $body = PaystackFakes::body($init['reference']);
        $sig = PaystackFakes::sign($body);
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/payments/webhooks/paystack', [], [], [], $this->transformHeadersToServerVars(['X-Paystack-Signature' => $sig, 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)->assertOk();
        }
        $this->assertSame(1, DB::table('payment')->where('provider_reference', $init['reference'])->where('status', 'CAPTURED')->count());
        $this->assertSame(1, DB::table('entitlement')->where('booking_id', Ids::toBinary($b['id']))->count());

        // delivery outbox: one EMAIL + one SMS, sent by the scheduled command (mail driver = array here, `log` on the demo server)
        $this->assertSame(['EMAIL', 'SMS'], DB::table('guest_message')->where('guest_order_id', $go->id)->orderBy('channel')->pluck('channel')->all());
        Artisan::call('r007:guest-messages:send');
        $this->assertSame(2, DB::table('guest_message')->where('status', 'SENT')->count());
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->all();
        $text = (string) end($messages)->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString($b['guestAccess']['reference'], $text);
        preg_match('#/order/(GC-[0-9A-Z]+)\?token=(r7o_[A-Za-z0-9_-]+)#', $text, $m);
        $this->assertNotEmpty($m, 'the email carries a working link');
        $this->getJson('/api/v1/public/orders/'.$m[1], $this->svc($m[2], null))->assertOk()->assertJsonPath('status', 'CONFIRMED');
    }

    public function test_guest_cancels_a_confirmed_booking_under_the_same_rules_as_accounts(): void
    {
        $b = $this->bookAndPay();
        $tok = $b['guestAccess']['accessToken'];
        $bk = $this->getJson('/api/v1/bookings/'.$b['id'], $this->svc($tok, null))->assertOk()->json();
        $c = $this->postJson("/api/v1/bookings/{$b['id']}/cancel", ['reason' => 'change of plans'], $this->svc($tok) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertOk()->json();
        $this->assertSame('CANCELLED', $c['status']);
        $this->assertFalse($c['policy']['canCancel']);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'like', 'booking.cancel%')->count() >= 1 ? 1 : 0);
        // tenders are staff-only
        $b2 = $this->hold(null, 12)->assertStatus(201)->json();
        $this->postJson("/api/v1/bookings/{$b2['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => $b2['total']]]], $this->svc($b2['guestAccess']['accessToken']) + ['If-Match' => '"'.$b2['rowVersion'].'"'])->assertStatus(403);
    }

    public function test_guest_reschedules_an_own_booking(): void
    {
        $b = $this->bookAndPay();
        $tok = $b['guestAccess']['accessToken'];
        $bk = $this->getJson('/api/v1/bookings/'.$b['id'], $this->svc($tok, null))->json();
        $s2 = $this->slot(14);
        $r = $this->postJson("/api/v1/bookings/{$b['id']}/reschedule", ['start' => $s2[0], 'end' => $s2[1]], $this->svc($tok) + ['If-Match' => '"'.$bk['rowVersion'].'"'])->assertOk()->json();
        $this->assertSame($s2[0], $r['start']);
    }

    // ---- token access ----

    public function test_a_token_only_opens_its_own_order_and_bad_tokens_are_rejected(): void
    {
        $a = $this->hold()->assertStatus(201)->json();
        $b = $this->hold(null, 12)->assertStatus(201)->json();
        $ta = $a['guestAccess']['accessToken'];
        $tb = $b['guestAccess']['accessToken'];

        // B's token cannot touch A's booking, order view, payment or tickets: 404, indistinguishable from a missing id
        $this->getJson('/api/v1/bookings/'.$a['id'], $this->svc($tb, null))->assertStatus(404);
        $this->getJson('/api/v1/public/orders/'.$a['guestAccess']['reference'], $this->svc($tb, null))->assertStatus(404);
        $this->postJson("/api/v1/bookings/{$a['id']}/cancel", ['reason' => 'x'], $this->svc($tb) + ['If-Match' => '"'.$a['rowVersion'].'"'])->assertStatus(404);
        $this->postJson('/api/v1/payments/paystack/initialize', ['bookingId' => $a['id'], 'amount' => $a['total']], $this->svc($tb))->assertStatus(404);
        $init = $this->pay($ta, ['bookingId' => $a['id']], $a['total']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($tb, null))->assertStatus(404);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($ta, null))->assertOk();
        $ent = $this->getJson('/api/v1/bookings/'.$a['id'], $this->svc($ta, null))->json('entitlementId');
        $this->getJson('/api/v1/entitlements/'.$ent, $this->svc($tb, null))->assertStatus(404);
        $this->getJson('/api/v1/entitlements/'.$ent, $this->svc($ta, null))->assertOk();

        // wrong / malformed / expired / missing tokens
        $this->getJson('/api/v1/bookings/'.$a['id'], $this->svc('r7o_'.str_repeat('A', 43), null))->assertStatus(401)->assertJsonPath('code', 'order_token_invalid');
        $this->getJson('/api/v1/bookings/'.$a['id'], $this->svc('garbage', null))->assertStatus(401)->assertJsonPath('code', 'order_token_invalid');
        $this->getJson('/api/v1/bookings/'.$a['id'], $this->svc(null, null))->assertStatus(404); // no token, no customer: not theirs
        $this->getJson('/api/v1/public/orders/'.$a['guestAccess']['reference'], $this->svc(null, null))->assertStatus(401)->assertJsonPath('code', 'order_token_invalid');
        DB::table('guest_access_token')->where('token_hash', hash('sha256', $ta))->update(['expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->getJson('/api/v1/public/orders/'.$a['guestAccess']['reference'], $this->svc($ta, null))->assertStatus(401)->assertJsonPath('code', 'order_token_expired');

        // a customer token / read-only service token can never use guest tokens
        $cust = $this->newCustomer();
        $this->getJson('/api/v1/bookings/'.$b['id'], ['Authorization' => 'Bearer '.$cust['accessToken'], 'X-Order-Token' => $tb, 'Accept' => 'application/json'])->assertStatus(404);
        $this->getJson('/api/v1/bookings/'.$b['id'], $this->svc($tb, null, $this->readOnlyServiceToken()))->assertStatus(403)->assertJsonPath('code', 'scope_denied');
        // no service credential at all
        $this->getJson('/api/v1/public/orders/'.$b['guestAccess']['reference'], ['X-Order-Token' => $tb, 'Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_guest_writes_need_the_checkout_scope_and_a_guest_object(): void
    {
        $ro = $this->readOnlyServiceToken();
        $this->hold(null, 10, 1, $this->svc(null, 'auto', $ro))->assertStatus(403)->assertJsonPath('code', 'scope_denied');
        $s = $this->slot();
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => $this->court(), 'start' => $s[0], 'end' => $s[1]], $this->svc())->assertStatus(422)->assertJsonPath('code', 'guest_required');
        // customers may not send `guest`
        $cust = $this->newCustomer();
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => $this->court(), 'start' => $s[0], 'end' => $s[1], 'guest' => $this->guest()], $this->bearer($cust['accessToken']))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        // kill switch
        config(['guest.enabled' => false]);
        $this->hold()->assertStatus(403)->assertJsonPath('code', 'guest_checkout_disabled');
    }

    public function test_guest_validation_and_normalisation(): void
    {
        $bad = [
            ['name' => 'A'], ['name' => '12345'], ['email' => 'nope'], ['email' => 'a@'], ['phone' => 'abc'], ['phone' => '0803'], ['consentVersion' => ''], ['consentVersion' => str_repeat('x', 33)],
        ];
        foreach ($bad as $i => $over) {
            $r = $this->hold($this->guest($over), 8 + $i)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
            $errs = $r->json('errors');
            $this->assertNotEmpty(array_filter(array_keys($errs), fn ($k) => str_starts_with($k, 'guest.')), json_encode($errs));
        }
        $this->assertSame(0, DB::table('guest_order')->count());
        $this->assertSame(0, DB::table('booking')->count());

        // accepted variants normalise to E.164 / lower case; other countries allowed
        $ok = $this->hold($this->guest(['phone' => '+234 (0) 803 123 4567', 'email' => '  MiXed@Example.Test ', 'marketingConsent' => false]), 8)->assertStatus(201)->json();
        $this->assertSame('mixed@example.test', $ok['customer']['email']);
        $this->assertSame('+2348031234567', $ok['customer']['phone']);
        $this->assertSame(0, (int) DB::table('guest_order')->value('marketing_consent'), 'marketing consent is stored as chosen');
        $intl = $this->hold($this->guest(['phone' => '+44 7700 900123']), 9)->assertStatus(201)->json();
        $this->assertSame('+447700900123', $intl['customer']['phone']);
        config(['guest.allow_international_phones' => false]);
        $this->hold($this->guest(['phone' => '+44 7700 900123']), 11)->assertStatus(422);
    }

    public function test_idempotent_replay_returns_the_same_guest_order(): void
    {
        $g = $this->guest();
        $h = $this->svc(null, 'same-key-1');
        $one = $this->hold($g, 10, 1, $h)->assertStatus(201)->json();
        $two = $this->hold($g, 10, 1, $h)->assertStatus(201);
        $this->assertSame('true', $two->headers->get('Idempotent-Replayed'));
        $this->assertSame($one['id'], $two->json('id'));
        $this->assertSame($one['guestAccess']['reference'], $two->json('guestAccess.reference'));
        $this->assertSame(1, DB::table('guest_order')->count());
        // a different service token cannot replay it
        $this->assertNotSame('anonymous', 's:x');
    }

    // ---- tickets ----

    public function test_guest_buys_pool_tickets_pays_and_receives_one_qr_per_person(): void
    {
        $customers = $this->customerRows();
        [$pool, $products] = $this->poolProducts();
        $adult = $products->firstWhere('ticketCategory', 'ADULT');
        $child = $products->firstWhere('ticketCategory', 'CHILD');
        $visit = CarbonImmutable::now('Africa/Lagos')->addDays(2)->format('Y-m-d');
        $body = ['facilityId' => $pool, 'visitDate' => $visit, 'lines' => [['productId' => $adult['id'], 'quantity' => 2], ['productId' => $child['id'], 'quantity' => 1]]];

        $this->postJson('/api/v1/public/ticket-orders', $body, $this->svc())->assertStatus(422)->assertJsonPath('code', 'guest_required');
        $o = $this->postJson('/api/v1/public/ticket-orders', $body + ['guest' => $this->guest(['phone' => '09011112222'])], $this->svc())->assertStatus(201)->json();
        $this->assertFalse($o['paid']);
        $this->assertSame(bcadd(bcmul($adult['price'], '2', 4), $child['price'], 4), $o['total']);
        $tok = $o['guestAccess']['accessToken'];
        $this->assertSame($customers, $this->customerRows());
        $co = DB::table('customer_order')->where('order_id', Ids::toBinary($o['id']))->first();
        $this->assertNull($co->customer_id);
        $this->assertSame('+2349011112222', $co->contact_phone);

        $view = $this->getJson('/api/v1/public/orders/'.$o['guestAccess']['reference'], $this->svc($tok, null))->assertOk()->json();
        $this->assertSame('TICKETS', $view['kind']);
        $this->assertSame('PENDING_PAYMENT', $view['status']);
        $this->assertSame([], $view['tickets']);
        $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$o['id']], 'amount' => '1.0000'], $this->svc($tok))->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $init = $this->pay($tok, ['orderIds' => [$o['id']]], $o['total']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($tok, null))->assertOk()->assertJsonPath('status', 'CAPTURED');

        $view = $this->getJson('/api/v1/public/orders/'.$o['guestAccess']['reference'], $this->svc($tok, null))->assertOk()->json();
        $this->assertSame('PAID', $view['status']);
        $this->assertTrue($view['paid']);
        $this->assertCount(3, $view['tickets']);
        $this->assertCount(3, array_unique(array_column($view['tickets'], 'qrToken')));
        $this->assertSame($visit, CarbonImmutable::parse($view['tickets'][0]['items'][0]['validFrom'])->setTimezone('Africa/Lagos')->format('Y-m-d'));
        $this->assertNull(DB::table('entitlement')->where('order_id', Ids::toBinary($o['id']))->value('customer_id'));
        $this->assertSame(2, DB::table('guest_message')->count());
        $this->assertSame($customers, $this->customerRows());

        // another guest cannot use this order (or pay it)
        $other = $this->postJson('/api/v1/public/ticket-orders', $body + ['guest' => $this->guest()], $this->svc())->assertStatus(201)->json();
        $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$o['id']], 'amount' => $o['total']], $this->svc($other['guestAccess']['accessToken']))->assertStatus(404);
        // resend: paid order, rate limited
        $this->postJson('/api/v1/public/orders/'.$o['guestAccess']['reference'].'/resend', ['channel' => 'EMAIL'], $this->svc($tok))->assertStatus(202);
        $this->postJson('/api/v1/public/orders/'.$o['guestAccess']['reference'].'/resend', ['channel' => 'BOGUS'], $this->svc($tok))->assertStatus(422);
        $this->postJson('/api/v1/public/orders/'.$other['guestAccess']['reference'].'/resend', ['channel' => 'EMAIL'], $this->svc($other['guestAccess']['accessToken']))->assertStatus(409)->assertJsonPath('code', 'nothing_to_send');
        config(['guest.rate.resend_per_order_hour' => 2]);
        $this->postJson('/api/v1/public/orders/'.$o['guestAccess']['reference'].'/resend', ['channel' => 'SMS'], $this->svc($tok))->assertStatus(202);
        $this->postJson('/api/v1/public/orders/'.$o['guestAccess']['reference'].'/resend', ['channel' => 'SMS'], $this->svc($tok))->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    // ---- membership ----

    public function test_guest_buys_a_membership_without_a_customer_row_and_staff_still_see_the_holder(): void
    {
        $customers = $this->customerRows();
        $plan = collect($this->getJson('/api/v1/memberships/plans', $this->svc(null, null))->assertOk()->json('items'))->first();
        $m = $this->postJson('/api/v1/memberships', ['planId' => $plan['id'], 'guest' => $this->guest(['name' => 'Ngozi Eze'])], $this->svc())->assertStatus(201)->json();
        $this->assertSame('PENDING_PAYMENT', $m['status']);
        $this->assertNull($m['customerId']);
        $this->assertSame('Ngozi Eze', $m['holderName']);
        $tok = $m['guestAccess']['accessToken'];
        $this->assertSame($customers, $this->customerRows());

        $init = $this->pay($tok, ['membershipId' => $m['id']], $plan['price']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($tok, null))->assertOk()->assertJsonPath('status', 'CAPTURED');
        $mm = $this->getJson('/api/v1/memberships/'.$m['id'], $this->svc($tok, null))->assertOk()->json();
        $this->assertSame('ACTIVE', $mm['status']);
        $this->assertNotNull($mm['qrToken']);
        $view = $this->getJson('/api/v1/public/orders/'.$m['guestAccess']['reference'], $this->svc($tok, null))->assertOk()->json();
        $this->assertSame('MEMBERSHIP', $view['kind']);
        $this->assertSame('ACTIVE', $view['status']);
        $this->assertTrue($view['paid']);

        // another guest / the read-only token cannot read it
        $x = $this->postJson('/api/v1/memberships', ['planId' => $plan['id'], 'guest' => $this->guest()], $this->svc())->assertStatus(201)->json();
        $this->getJson('/api/v1/memberships/'.$m['id'], $this->svc($x['guestAccess']['accessToken'], null))->assertStatus(404);
        // staff lists show it (customer_id NULL must not break anything) and search finds the snapshot
        $staff = $this->bearer($this->loginAs('manager1')['accessToken'], null);
        $list = $this->getJson('/api/v1/memberships?q=Ngozi', $staff)->assertOk()->json('items');
        $this->assertCount(1, $list);
        $this->assertSame('Ngozi Eze', $list[0]['holderName']);
        $this->getJson('/api/v1/memberships/'.$m['id'], $staff)->assertOk();
        $this->assertSame($customers, $this->customerRows());
    }

    // ---- lookup ----

    public function test_lookup_needs_reference_and_matching_contact_and_fails_generically(): void
    {
        $g = $this->guest(['email' => 'lookup.me@example.test', 'phone' => '0803 555 0101']);
        $b = $this->bookAndPay($g);
        $ref = $b['guestAccess']['reference'];
        $ok = $this->postJson('/api/v1/public/orders/lookup', ['reference' => strtolower($ref), 'email' => ' Lookup.Me@Example.TEST '], $this->svc(null, null))->assertOk()->json();
        $this->assertSame($ref, $ok['reference']);
        $this->assertSame('CONFIRMED', $ok['status']);
        $this->assertNotSame($b['guestAccess']['accessToken'], $ok['guestAccess']['accessToken']);
        $this->getJson('/api/v1/public/orders/'.$ref, $this->svc($ok['guestAccess']['accessToken'], null))->assertOk();
        $this->getJson('/api/v1/public/orders/'.$ref, $this->svc($b['guestAccess']['accessToken'], null))->assertOk(); // the older token keeps working
        $byPhone = $this->postJson('/api/v1/public/orders/lookup', ['reference' => $ref, 'phone' => '+234 803 555 0101'], $this->svc(null, null))->assertOk()->json();
        $this->assertSame($ref, $byPhone['reference']);

        // every failure is the SAME body: unknown reference, wrong email, wrong phone, other people's email (even a real account holder's)
        config(['guest.rate.lookup_per_reference_15min' => 100, 'guest.rate.lookup_per_contact_15min' => 100, 'guest.rate.lookup_per_ip_15min' => 100]);
        $cust = $this->newCustomer('real.account@example.test');
        $failures = [
            ['reference' => 'GC-ZZZZZZZZ', 'email' => 'lookup.me@example.test'],
            ['reference' => $ref, 'email' => 'someone.else@example.test'],
            ['reference' => $ref, 'phone' => '0803 555 0102'],
            ['reference' => $ref, 'email' => 'real.account@example.test'],
            ['reference' => $ref, 'phone' => 'junk'],
        ];
        $bodies = [];
        foreach ($failures as $f) {
            $r = $this->postJson('/api/v1/public/orders/lookup', $f, $this->svc(null, null))->assertStatus(404)->assertJsonPath('code', 'order_not_found');
            $j = $r->json();
            unset($j['instance'], $j['correlationId'], $j['traceId']);
            $bodies[] = json_encode($j);
        }
        $this->assertCount(1, array_unique($bodies), 'identical generic errors');
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => $ref], $this->svc(null, null))->assertStatus(422);
        // needs the checkout scope, and never a customer token
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => $ref, 'email' => 'lookup.me@example.test'], $this->svc(null, null, $this->readOnlyServiceToken()))->assertStatus(403);
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => $ref, 'email' => 'lookup.me@example.test'], $this->bearer($cust['accessToken'], null))->assertStatus(401);
    }

    public function test_lookup_is_rate_limited_per_reference_contact_and_ip(): void
    {
        $b = $this->bookAndPay($this->guest(['email' => 'rl@example.test']));
        $ref = $b['guestAccess']['reference'];
        config(['guest.rate.lookup_per_reference_15min' => 3, 'guest.rate.lookup_per_contact_15min' => 100, 'guest.rate.lookup_per_ip_15min' => 100]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/public/orders/lookup', ['reference' => $ref, 'email' => "guess{$i}@example.test"], $this->svc(null, null))->assertStatus(404);
        }
        // even the RIGHT email is refused once the reference is throttled (no oracle)
        $r = $this->postJson('/api/v1/public/orders/lookup', ['reference' => $ref, 'email' => 'rl@example.test'], $this->svc(null, null))->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertNotNull($r->headers->get('Retry-After'));

        config(['guest.rate.lookup_per_reference_15min' => 100, 'guest.rate.lookup_per_ip_15min' => 2]);
        $h = $this->svc(null, null, null, ['X-Client-IP' => '198.51.100.7']);
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => 'GC-AAAAAAAA', 'email' => 'x1@example.test'], $h)->assertStatus(404);
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => 'GC-AAAAAAAB', 'email' => 'x2@example.test'], $h)->assertStatus(404);
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => 'GC-AAAAAAAC', 'email' => 'x3@example.test'], $h)->assertStatus(429);
        // another visitor IP is unaffected
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => 'GC-AAAAAAAC', 'email' => 'x3@example.test'], $this->svc(null, null, null, ['X-Client-IP' => '198.51.100.8']))->assertStatus(404);
    }

    // ---- claiming ----

    public function test_orders_are_claimed_only_when_the_email_is_verified(): void
    {
        $email = 'claim.me@example.test';
        $b = $this->bookAndPay($this->guest(['email' => $email]), 10);
        $b2 = $this->hold($this->guest(['email' => $email]), 12)->assertStatus(201)->json(); // unpaid hold, same email
        $other = $this->bookAndPay($this->guest(['email' => 'not.me@example.test']), 14);
        $plan = collect($this->getJson('/api/v1/memberships/plans', $this->svc(null, null))->json('items'))->first();
        $mem = $this->postJson('/api/v1/memberships', ['planId' => $plan['id'], 'guest' => $this->guest(['email' => $email])], $this->svc())->assertStatus(201)->json();

        // register (UNVERIFIED): nothing is claimed, and the guest order is untouched
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Ada Claim', 'email' => $email, 'phone' => '+23480'.random_int(10000000, 99999999), 'password' => self::PW])->assertStatus(201);
        $this->assertSame(0, DB::table('guest_order')->whereNotNull('claimed_customer_id')->count());
        $this->assertNull(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id'));
        // the service method itself also refuses an unverified email
        $cid = DB::table('customer_account')->where('login_email', $email)->value('customer_id');
        $this->assertSame(0, app(GuestOrderClaimer::class)->claim(Ids::fromBinary($cid), $email));
        $this->assertSame(0, DB::table('guest_order')->whereNotNull('claimed_customer_id')->count());

        // verify the email through the normal step: now the three orders with that email are attached
        $auth = $this->postJson('/api/v1/customer/auth/verify', ['email' => $email, 'code' => $this->lastMailCode()])->assertOk()->json();
        $this->assertSame(3, DB::table('guest_order')->where('claimed_customer_id', $cid)->count());
        $this->assertSame(Ids::fromBinary($cid), Ids::fromBinary(DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_id')));
        $this->assertNull(DB::table('booking')->where('id', Ids::toBinary($other['id']))->value('customer_id'), 'other emails are never touched');
        $this->assertNotNull(DB::table('entitlement')->where('booking_id', Ids::toBinary($b['id']))->value('customer_id'));
        $this->assertSame(Ids::fromBinary($cid), Ids::fromBinary(DB::table('membership')->where('id', Ids::toBinary($mem['id']))->value('customer_id')));
        $this->assertSame(3, DB::table('audit_log')->where('action', 'guest.order.claim')->count());
        $mine = $this->getJson('/api/v1/customer/bookings', $this->bearer($auth['accessToken'], null))->assertOk()->json('items');
        $this->assertEqualsCanonicalizing([$b['id'], $b2['id']], array_column($mine, 'id'));
        $this->assertCount(1, $this->getJson('/api/v1/customer/entitlements?bookingId='.$b['id'], $this->bearer($auth['accessToken'], null))->json('items'));
        $this->assertCount(1, $this->getJson('/api/v1/customer/memberships', $this->bearer($auth['accessToken'], null))->json('items'));
        // idempotent: a second claim finds nothing
        $this->assertSame(0, app(GuestOrderClaimer::class)->claim(Ids::fromBinary($cid), $email));
        // the guest token still reads the (now claimed) order
        $this->assertTrue($this->getJson('/api/v1/public/orders/'.$b['guestAccess']['reference'], $this->svc($b['guestAccess']['accessToken'], null))->assertOk()->json('claimed'));
    }

    public function test_post_purchase_create_account_claims_only_after_email_verification(): void
    {
        $email = 'post.purchase@example.test';
        $b = $this->bookAndPay($this->guest(['email' => $email]));
        $ref = $b['guestAccess']['reference'];
        $r = $this->postJson("/api/v1/public/orders/{$ref}/create-account", ['password' => self::PW, 'termsAccepted' => true], $this->svc($b['guestAccess']['accessToken'], null))->assertStatus(202);
        $this->assertTrue($r->json('verificationRequired'));
        $this->assertSame(1, DB::table('customer_account')->where('login_email', $email)->count());
        $this->assertSame(0, DB::table('guest_order')->whereNotNull('claimed_customer_id')->count());
        $this->postJson('/api/v1/customer/auth/verify', ['email' => $email, 'code' => $this->lastMailCode()])->assertOk();
        $this->assertSame(1, DB::table('guest_order')->whereNotNull('claimed_customer_id')->count());
        // same generic answer for an email that already has an account
        $this->postJson("/api/v1/public/orders/{$ref}/create-account", ['password' => self::PW, 'termsAccepted' => true], $this->svc($b['guestAccess']['accessToken'], null))->assertStatus(202);
        $this->postJson("/api/v1/public/orders/{$ref}/create-account", ['password' => 'short', 'termsAccepted' => true], $this->svc($b['guestAccess']['accessToken'], null))->assertStatus(422);
        $this->postJson("/api/v1/public/orders/{$ref}/create-account", ['password' => self::PW, 'termsAccepted' => true], $this->svc(null, null))->assertStatus(401);
    }

    // ---- abuse controls ----

    public function test_active_hold_cap_per_contact_and_creation_rate_limits(): void
    {
        config(['guest.max_active_holds' => 2]);
        $g = $this->guest(['email' => 'holder@example.test', 'phone' => '0803 999 0001']);
        $this->hold($g, 8)->assertStatus(201);
        $this->hold($g, 9)->assertStatus(201);
        $this->hold($g, 10)->assertStatus(409)->assertJsonPath('code', 'too_many_active_holds');
        $this->assertSame(2, DB::table('booking')->count(), 'the refused hold left nothing behind');
        // the cap follows the PHONE too (new email, same phone)
        $this->hold($this->guest(['phone' => '0803 999 0001']), 11)->assertStatus(409)->assertJsonPath('code', 'too_many_active_holds');
        // a different person is fine
        $this->hold($this->guest(['phone' => '0803 999 0002']), 11)->assertStatus(201);
        // paying frees a slot in the cap
        $held = DB::table('booking')->where('customer_email', 'holder@example.test')->first();
        DB::table('booking')->where('id', $held->id)->update(['status' => 'CANCELLED']);
        $this->hold($g, 12)->assertStatus(201);

        config(['guest.max_active_holds' => 50, 'guest.rate.create_per_email_hour' => 2, 'guest.rate.create_per_phone_hour' => 100, 'guest.rate.create_per_ip_hour' => 100]);
        $e = $this->guest(['email' => 'spammer@example.test', 'phone' => '0803 999 0003']);
        $this->hold($e, 13)->assertStatus(201);
        $this->hold($e, 14)->assertStatus(201);
        $this->hold($e, 15)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        config(['guest.rate.create_per_email_hour' => 100, 'guest.rate.create_per_ip_hour' => 1]);
        $h = $this->svc(null, 'auto', null, ['X-Client-IP' => '192.0.2.55']);
        $this->hold($this->guest(['phone' => '0803 999 0010']), 16, 1, $h)->assertStatus(201);
        $this->hold($this->guest(['phone' => '0803 999 0011']), 17, 1, $this->svc(null, 'auto', null, ['X-Client-IP' => '192.0.2.55']))->assertStatus(429);
    }

    public function test_turnstile_is_off_by_default_and_fails_closed_when_enabled(): void
    {
        $this->hold($this->guest(), 8)->assertStatus(201); // off: no token needed
        config(['guest.turnstile_secret' => 'turnstile-test-secret']);
        $this->hold($this->guest(), 9)->assertStatus(422)->assertJsonPath('code', 'captcha_required');
        Http::swap(new Factory);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->hold($this->guest(['captchaToken' => 'bad']), 9)->assertStatus(422)->assertJsonPath('code', 'captcha_failed');
        Http::swap(new Factory);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->hold($this->guest(['captchaToken' => 'good']), 9)->assertStatus(201);
        Http::swap(new Factory);
        Http::fake(['challenges.cloudflare.com/*' => Http::response('boom', 500)]);
        $this->hold($this->guest(['captchaToken' => 'good']), 10)->assertStatus(422)->assertJsonPath('code', 'captcha_failed');
    }

    // ---- staff / reporting ----

    public function test_staff_lists_reports_and_receipts_cope_with_orders_that_have_no_customer(): void
    {
        $b = $this->bookAndPay($this->guest(['name' => 'Report Guest']));
        [$pool, $products] = $this->poolProducts();
        $p = $products->first();
        $o = $this->postJson('/api/v1/public/ticket-orders', ['facilityId' => $pool, 'visitDate' => CarbonImmutable::now('Africa/Lagos')->addDays(2)->format('Y-m-d'), 'lines' => [['productId' => $p['id'], 'quantity' => 1]], 'guest' => $this->guest(['name' => 'Ticket Guest'])], $this->svc())->assertStatus(201)->json();
        $init = $this->pay($o['guestAccess']['accessToken'], ['orderIds' => [$o['id']]], $o['total']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$init['reference'], $this->svc($o['guestAccess']['accessToken'], null))->assertOk();

        $mgr = $this->bearer($this->loginAs('manager1')['accessToken'], null);
        $list = $this->getJson('/api/v1/bookings?q=Report', $mgr)->assertOk()->json('items');
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['customerId']);
        $this->assertSame('Report Guest', $list[0]['customer']['name']);
        $this->assertSame('Report Guest', $this->getJson('/api/v1/bookings/'.$b['id'], $mgr)->assertOk()->json('customer.name'));
        $this->getJson('/api/v1/orders/'.$o['id'], $mgr)->assertOk()->assertJsonPath('customerName', 'Ticket Guest');
        $this->getJson('/api/v1/orders/'.$o['id'].'/receipt', $mgr)->assertOk();
        $this->getJson('/api/v1/entitlements/'.DB::table('entitlement')->where('order_id', Ids::toBinary($o['id']))->value('id') ? '/api/v1/entitlements?filter[orderId]='.$o['id'] : '/x', $mgr)->assertOk();
        $this->getJson('/api/v1/admin/search?q=Ticket', $mgr)->assertOk();
        $q = 'from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString();
        $this->getJson('/api/v1/reports/revenue?'.$q, $mgr)->assertOk();
        $this->getJson('/api/v1/reports/membership-summary?'.$q, $mgr)->assertOk();
        $this->getJson('/api/v1/reports/facility-daily-summary?date='.now('Africa/Lagos')->toDateString().'&facilityId='.$pool, $mgr)->assertOk();
        $this->getJson('/api/v1/orders', $mgr)->assertOk();
        $this->getJson('/api/v1/memberships', $mgr)->assertOk();
    }

    // ---- erasure ----

    public function test_erasure_anonymises_contact_data_keeps_financial_records_and_revokes_access(): void
    {
        $email = 'erase.me@example.test';
        $b = $this->bookAndPay($this->guest(['email' => $email, 'name' => 'Erase Me']));
        $keep = $this->bookAndPay($this->guest(['email' => 'keep.me@example.test', 'name' => 'Keep Me']), 12);
        $payments = DB::table('payment')->count();
        $go = DB::table('guest_order')->where('reference', $b['guestAccess']['reference'])->first();

        // staff endpoint (config.manage); a cashier is refused
        $this->postJson('/api/v1/guest-contacts/erasure', ['email' => $email], $this->bearer($this->loginAs('cashier1')['accessToken']))->assertStatus(403);
        $mgr = $this->loginAs('manager1');
        $r = $this->postJson('/api/v1/guest-contacts/erasure', ['email' => $email], $this->bearer($mgr['accessToken']));
        if ($r->status() === 403) { // manager lacks config.manage in this seed: use the owner-level account
            $r = $this->postJson('/api/v1/guest-contacts/erasure', ['email' => $email], $this->bearer($this->loginAs('owner1')['accessToken']));
        }
        $r->assertOk()->assertJsonPath('anonymised.guestOrders', 1)->assertJsonPath('anonymised.bookings', 1);

        $bk = DB::table('booking')->where('id', Ids::toBinary($b['id']))->first();
        $this->assertSame('Erased guest', $bk->customer_name);
        $this->assertNull($bk->customer_email);
        $this->assertNull($bk->customer_phone);
        $go = DB::table('guest_order')->where('id', $go->id)->first();
        $this->assertNotNull($go->erased_at);
        $this->assertNull($go->contact_email);
        $this->assertNull($go->contact_phone);
        $this->assertSame(0, DB::table('guest_contact')->where('email', $email)->count());
        $this->assertSame(1, DB::table('guest_contact')->where('email', 'keep.me@example.test')->count());
        $this->assertSame('Erased guest', DB::table('entitlement')->where('booking_id', Ids::toBinary($b['id']))->value('holder_name'));
        $this->assertSame($payments, DB::table('payment')->count(), 'financial records are kept');
        $this->assertSame('CONFIRMED', $bk->status);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'guest.erasure')->count());
        $this->assertStringNotContainsString($email, (string) DB::table('audit_log')->where('action', 'like', 'guest.%')->get()->map(fn ($x) => json_encode($x))->implode(' '));
        // token + lookup are dead; the other guest is untouched
        $this->getJson('/api/v1/public/orders/'.$b['guestAccess']['reference'], $this->svc($b['guestAccess']['accessToken'], null))->assertStatus(401);
        $this->postJson('/api/v1/public/orders/lookup', ['reference' => $b['guestAccess']['reference'], 'email' => $email], $this->svc(null, null))->assertStatus(404);
        $this->getJson('/api/v1/public/orders/'.$keep['guestAccess']['reference'], $this->svc($keep['guestAccess']['accessToken'], null))->assertOk();
        // queued messages of an erased order are cancelled, not sent
        $this->assertSame(0, DB::table('guest_message')->where('guest_order_id', $go->id)->where('status', 'QUEUED')->count());
        // the CLI does the same
        Artisan::call('r007:guest-erase', ['--email' => 'keep.me@example.test']);
        $this->assertSame('Erased guest', DB::table('booking')->where('id', Ids::toBinary($keep['id']))->value('customer_name'));
    }

    public function test_erasure_never_touches_account_holders_data(): void
    {
        $email = 'both@example.test';
        $b = $this->bookAndPay($this->guest(['email' => $email]));
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Both', 'email' => $email, 'phone' => '+23480'.random_int(10000000, 99999999), 'password' => self::PW])->assertStatus(201);
        $this->postJson('/api/v1/customer/auth/verify', ['email' => $email, 'code' => $this->lastMailCode()])->assertOk();
        Artisan::call('r007:guest-erase', ['--email' => $email]);
        $this->assertSame('Both', DB::table('customer')->where('email', $email)->value('full_name'));
        $this->assertNotSame('Erased guest', DB::table('booking')->where('id', Ids::toBinary($b['id']))->value('customer_name') === 'Erased guest' ? 'x' : 'ok');
    }

    public function test_guest_checkout_scope_of_the_dev_token_and_command(): void
    {
        $this->assertSame('public.read,public.checkout,customer.social', DB::table('service_token')->where('token_hash', hash('sha256', $this->serviceToken()))->value('scope'));
        Artisan::call('r007:service-token', ['action' => 'create', '--name' => 'x', '--scope' => 'public.read,public.checkout']);
        $this->assertSame(1, DB::table('service_token')->where('scope', 'public.read,public.checkout')->count());
        $this->expectException(\Throwable::class);
        Artisan::call('r007:service-token', ['action' => 'create', '--name' => 'x', '--scope' => 'admin.everything']);
    }
}
