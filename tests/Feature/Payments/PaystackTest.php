<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Domain\Payments\Events\PaymentCaptured;
use App\Support\Ids;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsWorld;
use Tests\Support\PaystackFakes;
use Tests\TestCase;

class PaystackTest extends TestCase
{
    use PaymentsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        PaystackFakes::configure();
        PaystackFakes::http();
    }

    /** @return array{order: string, paymentId: string, reference: string} */
    private function initFor(string $total = '9000.0000'): array
    {
        $order = $this->makeOrder($total);
        $r = $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$order], 'amount' => $total, 'email' => 'guest@example.test'], $this->auth($this->cashierToken))->assertCreated();
        PaystackFakes::remember($r->json('reference'), $total);

        return ['order' => $order, 'paymentId' => $r->json('paymentId'), 'reference' => $r->json('reference')];
    }

    private function webhook(string $body, ?string $sig = 'auto', array $headers = [])
    {
        $sig = $sig === 'auto' ? PaystackFakes::sign($body) : $sig;

        return $this->call('POST', '/api/v1/payments/webhooks/paystack', [], [], [], $this->transformHeadersToServerVars(($sig ? ['X-Paystack-Signature' => $sig] : []) + $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $body);
    }

    public function test_initialize_creates_an_authorizing_payment_and_sends_kobo_to_paystack(): void
    {
        $x = $this->initFor('9000.0000');
        $this->assertStringStartsWith('R007-', $x['reference']);
        $row = DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->first();
        $this->assertSame('AUTHORIZING', $row->status);
        $this->assertSame('PAYSTACK', $row->provider);
        $this->assertSame($x['reference'], $row->provider_reference);
        $this->assertSame('9000.0000', (string) $row->amount);
        $this->assertNull($row->captured_at);
        $this->assertSame(0, DB::table('payment_allocation')->count(), 'nothing is allocated until Paystack confirms');
        $this->assertSame('PARTIAL', $this->orderStatus($x['order']) === 'SERVED' ? 'PARTIAL' : 'BAD'); // order untouched

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['amount'] === '900000' && $r['currency'] === 'NGN'
            && $r['reference'] === $x['reference'] && $r->hasHeader('Authorization'));
    }

    public function test_initialize_amount_must_equal_the_server_balance_and_needs_exactly_one_subject(): void
    {
        $order = $this->makeOrder('9000.0000');
        $h = $this->auth($this->cashierToken);
        $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$order], 'amount' => '8000.0000', 'email' => 'g@example.test'], $h)->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->postJson('/api/v1/payments/paystack/initialize', ['amount' => '8000.0000', 'email' => 'g@example.test'], $this->auth($this->cashierToken))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$order], 'bookingId' => Ids::uuid7(), 'amount' => '9000.0000', 'email' => 'g@example.test'], $this->auth($this->cashierToken))->assertStatus(422);
        $this->postJson('/api/v1/payments/paystack/initialize', ['bookingId' => Ids::uuid7(), 'amount' => '9000.0000', 'email' => 'g@example.test'], $this->auth($this->cashierToken))
            ->assertStatus(422)->assertJsonPath('code', 'payable_subject_unsupported');
        $this->assertSame(0, DB::table('payment')->count());
    }

    public function test_provider_failure_on_initialize_is_a_502_and_leaves_no_payment(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
        $order = $this->makeOrder('1000.0000');
        $r = $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$order], 'amount' => '1000.0000', 'email' => 'g@example.test'], $this->auth($this->cashierToken))
            ->assertStatus(502)->assertJsonPath('code', 'provider_error');
        $this->assertStringNotContainsString(PaystackFakes::SECRET, $r->getContent());
        $this->assertSame(0, DB::table('payment')->count());
    }

    public function test_webhook_with_a_bad_or_missing_signature_is_rejected_logged_and_not_processed(): void
    {
        $x = $this->initFor();
        $body = PaystackFakes::body($x['reference']);
        $this->webhook($body, PaystackFakes::sign($body, 'some-other-accounts-secret'))->assertStatus(401);
        $this->webhook($body, null)->assertStatus(401);
        $this->webhook($body.' ', PaystackFakes::sign($body))->assertStatus(401); // signature covers the RAW body byte for byte

        $this->assertSame('AUTHORIZING', DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->value('status'));
        $this->assertSame(0, DB::table('provider_event')->count());
        $events = DB::table('security_event')->where('event_type', 'payment.webhook_signature_invalid')->get();
        $this->assertCount(3, $events);
        $blob = json_encode($events);
        $this->assertStringNotContainsString(PaystackFakes::SECRET, $blob);
        $this->assertStringNotContainsString(PaystackFakes::sign($body), $blob);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/verify/')); // never even asked Paystack
    }

    public function test_valid_webhook_is_verified_with_paystack_then_captures_settles_and_receipts(): void
    {
        $x = $this->initFor('9000.0000');
        $body = PaystackFakes::body($x['reference']);

        $this->webhook($body)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

        $p = DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->first();
        $this->assertSame('CAPTURED', $p->status);
        $this->assertNotNull($p->captured_at);
        $this->assertSame('4099260516', $p->provider_txn_id);
        $this->assertNotNull($p->receipt_id);
        $this->assertSame('SETTLED', $this->orderStatus($x['order']));
        $this->assertSame('9000.0000', $this->paidOf($x['order']));
        $this->assertSame(1, DB::table('provider_event')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OnlinePaymentConfirmed')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.capture.online')->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transaction/verify/'.$x['reference']));

        // Paystack redelivers: no-op, no second verify call needed, still exactly one payment / allocation / event
        $before = count(Http::recorded());
        $this->webhook($body)->assertOk()->assertJson(['received' => true, 'duplicate' => true]);
        $this->assertSame($before, count(Http::recorded()));
        $this->assertSame(1, DB::table('payment_allocation')->count());
        $this->assertSame(1, DB::table('provider_event')->count());
        $this->assertSame('9000.0000', $this->paidOf($x['order']));
    }

    public function test_a_signed_webhook_alone_never_captures_paystack_must_confirm(): void
    {
        $x = $this->initFor('9000.0000');
        PaystackFakes::http('abandoned');
        $this->webhook(PaystackFakes::body($x['reference']))->assertOk();
        $this->assertSame('AUTHORIZING', DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->value('status'));
        $this->assertSame('SERVED', $this->orderStatus($x['order']));

        PaystackFakes::http('failed');
        $this->webhook(PaystackFakes::body($x['reference'], 7))->assertOk();
        $this->assertSame('FAILED', DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->value('status'));
        $this->assertSame(0, DB::table('payment_allocation')->count());
    }

    public function test_confirmed_amount_that_differs_from_the_payment_fails_it_and_raises_a_security_event(): void
    {
        $x = $this->initFor('9000.0000');
        PaystackFakes::http('success', 100000); // Paystack says N1,000 was paid
        $this->webhook(PaystackFakes::body($x['reference']))->assertOk();
        $p = DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->first();
        $this->assertSame('FAILED', $p->status);
        $this->assertSame('provider_amount_mismatch', $p->failure_reason);
        $this->assertSame('SERVED', $this->orderStatus($x['order']));
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'payment.provider_amount_mismatch')->where('severity', 'CRITICAL')->count());
    }

    public function test_verify_endpoint_and_webhook_are_idempotent_together(): void
    {
        $x = $this->initFor('4000.0000');
        $r = $this->getJson('/api/v1/payments/paystack/verify/'.$x['reference'], $this->auth($this->cashierToken, null))->assertOk();
        $r->assertJsonPath('status', 'CAPTURED')->assertJsonPath('providerReference', $x['reference']);
        $this->getJson('/api/v1/payments/paystack/verify/'.$x['reference'], $this->auth($this->cashierToken, null))->assertOk()->assertJsonPath('status', 'CAPTURED');
        $this->webhook(PaystackFakes::body($x['reference']))->assertOk();
        $this->assertSame(1, DB::table('payment_allocation')->count());
        $this->assertSame('4000.0000', $this->paidOf($x['order']));
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OnlinePaymentConfirmed')->count());
        $this->getJson('/api/v1/payments/paystack/verify/R007-NOPE', $this->auth($this->cashierToken, null))->assertNotFound();
    }

    public function test_online_money_for_an_order_settled_meanwhile_is_captured_but_flagged_unallocated(): void
    {
        $x = $this->initFor('6000.0000');
        // the guest pays cash at the till before the online payment lands
        $session = $this->openSession();
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $x['order'], 'amount' => '6000.0000']], [['tenderType' => 'CASH', 'amount' => '6000.0000']], $session), $this->auth($this->cashierToken))->assertCreated();

        $this->webhook(PaystackFakes::body($x['reference']))->assertOk();
        $p = DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->first();
        $this->assertSame('CAPTURED', $p->status, 'the money really arrived');
        $this->assertSame('6000.0000', (string) $p->unallocated_amount);
        $this->assertSame('6000.0000', $this->paidOf($x['order']), 'never over-allocated');
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'payment.online_overpayment')->count());
    }

    public function test_unknown_references_and_unrelated_events_are_acknowledged_and_recorded_only(): void
    {
        $body = PaystackFakes::body('R007-NEVERISSUED', 555, 'charge.success', 100);
        $this->webhook($body)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
        $this->webhook($body)->assertOk()->assertJson(['duplicate' => true]);
        $tr = json_encode(['event' => 'transfer.success', 'data' => ['id' => 9, 'reference' => 'TRF1']]);
        $this->webhook($tr)->assertOk();
        $this->assertSame(2, DB::table('provider_event')->count());
        $this->webhook('not json', PaystackFakes::sign('not json'))->assertStatus(400);
        $this->postJson('/api/v1/payments/webhooks/flutterwave', [])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_optional_source_ip_allow_list(): void
    {
        $x = $this->initFor();
        config(['payments.paystack.webhook_allowed_ips' => ['52.31.139.75']]);
        $body = PaystackFakes::body($x['reference']);
        $this->webhook($body)->assertStatus(401); // 127.0.0.1 is not on the list
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'payment.webhook_ip_rejected')->count());
        $this->call('POST', '/api/v1/payments/webhooks/paystack', [], [], [], ['REMOTE_ADDR' => '52.31.139.75', 'HTTP_X_PAYSTACK_SIGNATURE' => PaystackFakes::sign($body), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertOk();
        $this->assertSame('CAPTURED', DB::table('payment')->where('id', Ids::toBinary($x['paymentId']))->value('status'));
    }

    public function test_booking_payment_via_a_bound_resolver_fires_payment_captured_with_the_subject(): void
    {
        $booking = Ids::uuid7();
        $facility = $this->facility;
        $this->app->bind(PayableSubjectResolver::class, fn () => new class($facility) implements PayableSubjectResolver
        {
            public function __construct(private string $f) {}

            public function resolve(string $type, string $id): ?array
            {
                return ['amountDue' => '15000.0000', 'facilityId' => $this->f];
            }
        });
        Event::fake([PaymentCaptured::class]);
        $r = $this->postJson('/api/v1/payments/paystack/initialize', ['bookingId' => $booking, 'amount' => '15000.0000', 'email' => 'g@example.test'], $this->auth($this->cashierToken))->assertCreated();
        PaystackFakes::remember($r->json('reference'), '15000.0000');
        $this->webhook(PaystackFakes::body($r->json('reference')))->assertOk();

        $p = DB::table('payment')->where('id', Ids::toBinary($r->json('paymentId')))->first();
        $this->assertSame('CAPTURED', $p->status);
        $this->assertNull($p->receipt_id);
        Event::assertDispatched(PaymentCaptured::class, fn (PaymentCaptured $e) => $e->subjectType === 'BOOKING' && $e->subjectId === $booking && $e->amount === '15000.0000'
            && $e->provider === 'PAYSTACK' && $e->paymentId === $r->json('paymentId'));
    }
}
