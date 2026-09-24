<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Support\Ledger;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\PaymentsWorld;
use Tests\Support\PaymentWorkers;
use Tests\Support\PaystackFakes;

/**
 * Real concurrency against real MySQL: separate PHP processes released at the same instant (architecture/18 §3, C-1..C-4).
 * The invariant under test everywhere: money is never over-allocated, exactly-once effects, clear 409s for the losers.
 */
class PaymentsConcurrencyTest extends ConcurrentTestCase
{
    use PaymentsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @param list<array{index: int, result: mixed, error: ?string}> $results @return list<array{0: int, 1: mixed, 2: ?string}> */
    private function ok(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $out[] = $r['result'];
        }

        return $out;
    }

    private function statuses(array $results): array
    {
        $c = [];
        foreach ($results as $r) {
            $c[$r[0]] = ($c[$r[0]] ?? 0) + 1;
        }
        ksort($c);

        return $c;
    }

    public function test_eight_simultaneous_settlements_of_the_same_order_exactly_one_wins_nothing_over_allocated(): void
    {
        $order = $this->makeOrder('9000.0000');
        $session = $this->openSession();
        $body = $this->payBody([['orderId' => $order, 'amount' => '9000.0000']], [['tenderType' => 'CASH', 'amount' => '9000.0000', 'tendered' => '10000.0000']], $session);

        $res = $this->ok(Concurrent::run(8, PaymentWorkers::class, 'callOwnKey', ['POST', '/api/v1/payments', $this->cashierToken, 'race-a', $body]));

        $this->assertSame([201 => 1, 409 => 7], $this->statuses($res));
        foreach ($res as $r) {
            if ($r[0] === 409) {
                $this->assertSame('balance_changed', $r[1]['code']);
                $this->assertSame('0.0000', $r[1]['balanceDue']);
            }
        }
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame(1, DB::table('payment_allocation')->count());
        $this->assertSame(1, DB::table('receipt')->count());
        $this->assertSame('9000.0000', $this->paidOf($order));
        $this->assertSame('SETTLED', $this->orderStatus($order));
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'PaymentCompleted')->count());
    }

    public function test_partial_settlements_race_never_exceed_the_order_total(): void
    {
        $order = $this->makeOrder('10000.0000');
        $session = $this->openSession();
        $body = $this->payBody([['orderId' => $order, 'amount' => '3000.0000']], [['tenderType' => 'CASH', 'amount' => '3000.0000']], $session);

        $res = $this->ok(Concurrent::run(8, PaymentWorkers::class, 'callOwnKey', ['POST', '/api/v1/payments', $this->cashierToken, 'race-b', $body]));

        $this->assertSame([201 => 3, 409 => 5], $this->statuses($res), 'only 3 x 3000 fit into 10000');
        $this->assertSame('9000.0000', $this->paidOf($order));
        $this->assertSame('SERVED', $this->orderStatus($order));
        $this->assertSame('9000.0000', (string) DB::table('payment_allocation')->sum('amount'));
    }

    public function test_two_devices_settling_the_same_tab_at_once_second_gets_balance_changed(): void
    {
        $tab = $this->makeTab();
        $a = $this->makeOrder('4000.0000', 'SERVED', $tab);
        $b = $this->makeOrder('5000.0000', 'SERVED', $tab);
        $session = $this->openSession();
        $body = ['tenders' => [['tenderType' => 'CASH', 'amount' => '9000.0000', 'tendered' => '9000.0000']], 'cashSessionId' => $session];

        $res = $this->ok(Concurrent::run(6, PaymentWorkers::class, 'callOwnKey', ['POST', "/api/v1/tabs/{$tab}/settle", $this->cashierToken, 'tab-race', $body]));

        $this->assertSame([200 => 1, 409 => 5], $this->statuses($res));
        foreach ($res as $r) {
            if ($r[0] === 409) {
                $this->assertSame('balance_changed', $r[1]['code']);
            }
        }
        $this->assertSame('9000.0000', (string) DB::table('payment')->sum('amount'));
        $this->assertSame('4000.0000', $this->paidOf($a));
        $this->assertSame('5000.0000', $this->paidOf($b));
        $this->assertSame('SETTLED', DB::table('tab')->where('id', Ids::toBinary($tab))->value('status'));
    }

    public function test_a_tab_settle_racing_an_order_payment_never_double_pays(): void
    {
        $tab = $this->makeTab();
        $a = $this->makeOrder('4000.0000', 'SERVED', $tab);
        $this->makeOrder('5000.0000', 'SERVED', $tab);
        $session = $this->openSession();
        // 4 workers settle the tab, 4 pay order A directly, all released together
        $tabBody = ['tenders' => [['tenderType' => 'CASH', 'amount' => '9000.0000']], 'cashSessionId' => $session];
        $orderBody = $this->payBody([['orderId' => $a, 'amount' => '4000.0000']], [['tenderType' => 'CASH', 'amount' => '4000.0000']], $session);

        $res = $this->ok(Concurrent::run(8, PaymentWorkers::class, 'mixed', [$this->cashierToken, $tab, $tabBody, $orderBody]));

        foreach (DB::table('order')->get() as $o) {
            $paid = Ledger::paidByOrder([Ids::fromBinary($o->id)])[Ids::fromBinary($o->id)];
            $this->assertLessThanOrEqual(0, bccomp($paid, (string) $o->total, 4), 'no order is over-paid');
        }
        $this->assertSame(2, DB::table('order')->where('status', 'SETTLED')->count());
        $succ = array_filter($res, fn ($r) => in_array($r[0], [200, 201], true));
        $this->assertLessThanOrEqual(2, count($succ), 'at most: {order A directly + tab remainder} or {whole tab}');
        $this->assertSame('9000.0000', (string) DB::table('payment_allocation')->sum('amount'));
    }

    public function test_duplicate_idempotency_key_sent_six_times_at_once_applies_once(): void
    {
        $order = $this->makeOrder('2500.0000');
        $session = $this->openSession();
        $body = $this->payBody([['orderId' => $order, 'amount' => '2500.0000']], [['tenderType' => 'CASH', 'amount' => '2500.0000']], $session);

        $res = $this->ok(Concurrent::run(6, PaymentWorkers::class, 'call', ['POST', '/api/v1/payments', $this->cashierToken, 'the-same-key', $body]));

        $this->assertSame([201 => 6], $this->statuses($res));
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r[1]['payments'][0]['id'], $res)));
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame(1, DB::table('idempotency_record')->count());
        $this->assertSame('2500.0000', $this->paidOf($order));
    }

    public function test_duplicate_client_tender_id_with_different_idempotency_keys_creates_one_payment(): void
    {
        $order = $this->makeOrder('2500.0000');
        $session = $this->openSession();
        $tenderId = Ids::uuid7();
        $body = $this->payBody([['orderId' => $order, 'amount' => '2500.0000']], [['id' => $tenderId, 'tenderType' => 'CASH', 'amount' => '2500.0000']], $session);

        $res = $this->ok(Concurrent::run(6, PaymentWorkers::class, 'callOwnKey', ['POST', '/api/v1/payments', $this->cashierToken, 'offline-q', $body]));

        $this->assertSame([201 => 6], $this->statuses($res), 'the offline queue retries are replays, not conflicts');
        foreach ($res as $r) {
            $this->assertSame($tenderId, $r[1]['payments'][0]['id']);
        }
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame('2500.0000', $this->paidOf($order));
    }

    public function test_three_simultaneous_identical_paystack_webhooks_create_exactly_one_payment(): void
    {
        PaystackFakes::configure();
        $order = $this->makeOrder('9000.0000');
        // Paystack initialize is faked in THIS process; the webhook workers only need the resulting reference
        PaystackFakes::http();
        $init = $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$order], 'amount' => '9000.0000', 'email' => 'guest@example.test'], $this->auth($this->cashierToken))->assertCreated();
        $reference = $init->json('reference');
        $body = PaystackFakes::body($reference, 4099260516, 'charge.success', 900000);

        $res = $this->ok(Concurrent::run(3, PaymentWorkers::class, 'webhook', [$body, 900000]));

        foreach ($res as $r) {
            $this->assertSame(200, $r[0]);
            $this->assertTrue($r[1]['received']);
        }
        $this->assertSame(1, count(array_filter($res, fn ($r) => $r[1]['duplicate'] === false)), 'exactly one delivery did the work');
        $this->assertSame(2, count(array_filter($res, fn ($r) => $r[1]['duplicate'] === true)));
        $this->assertSame(1, DB::table('payment')->count(), 'still exactly one payment');
        $this->assertSame('CAPTURED', DB::table('payment')->value('status'));
        $this->assertSame(1, DB::table('provider_event')->count());
        $this->assertSame(1, DB::table('payment_allocation')->count());
        $this->assertSame(1, DB::table('receipt')->count());
        $this->assertSame('9000.0000', $this->paidOf($order));
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OnlinePaymentConfirmed')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.capture.online')->count());
    }

    public function test_webhooks_with_different_event_ids_for_one_payment_still_capture_once(): void
    {
        PaystackFakes::configure();
        PaystackFakes::http();
        $order = $this->makeOrder('4000.0000');
        $init = $this->postJson('/api/v1/payments/paystack/initialize', ['orderIds' => [$order], 'amount' => '4000.0000', 'email' => 'guest@example.test'], $this->auth($this->cashierToken))->assertCreated();
        // a misbehaving sender uses a different transaction id in each copy: the payment row lock + status still make it exactly-once
        $results = [];
        foreach ([111, 222, 333] as $txn) {
            $results[] = $this->ok(Concurrent::run(1, PaymentWorkers::class, 'webhook', [PaystackFakes::body($init->json('reference'), $txn, 'charge.success', 400000), 400000]))[0];
        }
        $this->assertSame(1, DB::table('payment_allocation')->count());
        $this->assertSame('4000.0000', $this->paidOf($order));
        $this->assertSame(3, DB::table('provider_event')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OnlinePaymentConfirmed')->count());
    }

    public function test_eight_concurrent_refunds_of_one_thousand_never_exceed_the_captured_five_thousand(): void
    {
        $order = $this->makeOrder('5000.0000');
        $pay = $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '5000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '5000.0000', 'reference' => 'RF-1']]), $this->auth($this->cashierToken))->assertCreated();
        $paymentId = $pay->json('payments.0.id');

        $res = $this->ok(Concurrent::run(8, PaymentWorkers::class, 'callOwnKey', ['POST', "/api/v1/payments/{$paymentId}/refund", $this->supervisorToken, 'rf', ['amount' => '1000.0000', 'reason' => 'race refund']]));

        $this->assertSame(5, count(array_filter($res, fn ($r) => $r[0] === 201)));
        foreach ($res as $r) {
            if ($r[0] !== 201) {
                $this->assertContains($r[1]['code'], ['amount_mismatch', 'payment_state_invalid']);
            }
        }
        $p = DB::table('payment')->where('id', Ids::toBinary($paymentId))->first();
        $this->assertSame('5000.0000', (string) $p->refunded_amount);
        $this->assertSame('REFUNDED', $p->status);
        $this->assertSame('5000.0000', (string) DB::table('refund')->sum('amount'));
        $this->assertSame(5, DB::table('refund')->count());
    }

    public function test_refund_and_reversal_racing_on_one_payment_only_one_takes_effect(): void
    {
        $order = $this->makeOrder('5000.0000');
        $session = $this->openSession();
        $paymentId = $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '5000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '5000.0000', 'reference' => 'RV-1']], $session), $this->auth($this->cashierToken))->assertCreated()->json('payments.0.id');

        $res = $this->ok(Concurrent::run(6, PaymentWorkers::class, 'refundOrReverse', [$this->supervisorToken, $paymentId]));

        $this->assertSame(1, count(array_filter($res, fn ($r) => $r[0] === 201)), 'a full refund and a reversal cannot both happen');
        $this->assertSame(1, DB::table('refund')->count() + DB::table('reversal')->count());
        $this->assertContains(DB::table('payment')->where('id', Ids::toBinary($paymentId))->value('status'), ['REFUNDED', 'REVERSED']);
    }

    public function test_cash_session_close_racing_payments_keeps_expected_cash_consistent(): void
    {
        $session = $this->openSession(null, '1000.0000');
        $orders = [];
        for ($i = 0; $i < 6; $i++) {
            $orders[] = $this->makeOrder('1500.0000');
        }

        $res = $this->ok(Concurrent::run(7, PaymentWorkers::class, 'payOrClose', [$this->cashierToken, $session, $this->facility, $orders]));

        $row = DB::table('cash_session')->where('id', Ids::toBinary($session))->first();
        $this->assertSame('CLOSED', $row->status);
        $totals = Ledger::cashTotals($session);
        $this->assertSame(Ledger::expectedCash('1000.0000', $totals), (string) $row->expected_cash, 'nothing was booked into the session after it closed');
        $paidInSession = DB::table('payment')->where('cash_session_id', Ids::toBinary($session))->count();
        $this->assertSame(bcadd('1000.0000', bcmul('1500', (string) $paidInSession, 4), 4), (string) $row->expected_cash);
        foreach ($res as $r) {
            if ($r[0] >= 400 && $r[0] !== 200) {
                $this->assertSame(409, $r[0]);
                $this->assertContains($r[1]['code'], ['cash_session_required', 'cash_session_closed']);
            }
        }
    }

    public function test_only_one_cash_session_opens_when_a_cashier_double_taps(): void
    {
        $res = $this->ok(Concurrent::run(6, PaymentWorkers::class, 'callOwnKey', ['POST', '/api/v1/cash-sessions', $this->cashierToken, 'open', ['facilityId' => $this->facility, 'openingFloat' => '5000.0000']]));
        $this->assertSame([201 => 1, 409 => 5], $this->statuses($res));
        $this->assertSame(1, DB::table('cash_session')->where('status', 'OPEN')->count());
    }

    public function test_concurrent_payments_into_the_ledger_keep_the_audit_chain_valid(): void
    {
        $session = $this->openSession();
        for ($i = 0; $i < 6; $i++) {
            $order = $this->makeOrder('100.0000');
            $bodies[] = $this->payBody([['orderId' => $order, 'amount' => '100.0000']], [['tenderType' => 'CASH', 'amount' => '100.0000']], $session);
        }
        $this->ok(Concurrent::run(6, PaymentWorkers::class, 'eachBody', [$this->cashierToken, $bodies]));
        $this->assertSame(6, DB::table('payment')->count());
        $v = Audit::verifyChain();
        $this->assertTrue($v->valid, (string) $v->reason);
    }
}
