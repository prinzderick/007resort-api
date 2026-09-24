<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Support\Ledger;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\CollectionWorkers;
use Tests\Support\CollectionWorld;
use Tests\Support\Concurrent;
use Tests\Support\PaymentWorkers;
use Tests\Support\PaystackFakes;

/**
 * Real concurrency against real MySQL for waiter collection: separate PHP processes released at the same instant.
 * Invariant everywhere: captured + pending never exceeds the order total, exactly-once effects, clear 409s for the losers.
 */
class WaiterCollectionConcurrencyTest extends ConcurrentTestCase
{
    use CollectionWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCollectionWorld(cashHolding: true);
    }

    /** One app instance serves several requests as different users here: drop cached guard users like a fresh request would. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->defaultHeaders = [];
        }
    }

    /** @param list<array{index: int, result: mixed, error: ?string}> $results @return list<array{0: int, 1: mixed}> */
    private function ok(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $out[] = $r['result'];
        }

        return $out;
    }

    /** @return array<int, int> status => count */
    private function tally(array $res): array
    {
        $c = [];
        foreach ($res as $r) {
            $c[$r[0]] = ($c[$r[0]] ?? 0) + 1;
        }
        ksort($c);

        return $c;
    }

    private function actors(): array
    {
        return [[$this->waiterToken, $this->deviceToken], [$this->waiter2Token, $this->device2Token]];
    }

    private function assertNeverOverCollected(string $order): void
    {
        $total = (string) DB::table('order')->where('id', Ids::toBinary($order))->value('total');
        $paid = Ledger::paidByOrder([$order])[$order];
        $pending = Ledger::pendingByOrder([$order])[$order];
        $this->assertLessThanOrEqual(0, bccomp(bcadd($paid, $pending, 4), $total, 4), "captured {$paid} + pending {$pending} exceeds total {$total}");
    }

    public function test_two_waiters_racing_to_collect_a_whole_bill_exactly_one_wins(): void
    {
        $order = $this->billedOrder('9000.0000');
        $body = ['tenderType' => 'CARD_TERMINAL', 'amount' => '9000.0000'];
        $res = $this->ok(Concurrent::run(8, CollectionWorkers::class, 'call', [$this->actors(), 'POST', "/api/v1/orders/{$order}/collections", 'race', $body]));

        $this->assertSame([201 => 1, 409 => 7], $this->tally($res));
        foreach ($res as $r) {
            if ($r[0] === 409) {
                $this->assertSame('over_collection', $r[1]['code']);
            }
        }
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame(1, DB::table('payment_allocation')->count());
        $this->assertSame('9000.0000', Ledger::pendingByOrder([$order])[$order]);
        $this->assertNeverOverCollected($order);
    }

    public function test_partial_collections_from_many_workers_never_exceed_the_balance(): void
    {
        $order = $this->billedOrder('9000.0000');
        // approvalCode differs per worker via the body; use TRANSFER without reference (no uniqueness) so only the balance decides
        $body = ['tenderType' => 'TRANSFER', 'amount' => '2000.0000'];
        $res = $this->ok(Concurrent::run(10, CollectionWorkers::class, 'call', [$this->actors(), 'POST', "/api/v1/orders/{$order}/collections", 'part', $body]));

        $this->assertSame([201 => 4, 409 => 6], $this->tally($res), '4 x 2000 fits in 9000, the 5th cannot');
        $this->assertSame('8000.0000', Ledger::pendingByOrder([$order])[$order]);
        $this->assertNeverOverCollected($order);
    }

    public function test_waiter_and_cashier_settling_the_same_bill_at_once_never_over_collect(): void
    {
        $order = $this->billedOrder('9000.0000');
        $session = $this->openSession($this->cashier);
        $res = $this->ok(Concurrent::run(8, CollectionWorkers::class, 'collectOrPay', [[$this->waiterToken, $this->deviceToken], [$this->cashierToken, null], $order, $this->facility, $session, '9000.0000']));

        $statuses = $this->tally($res);
        $this->assertSame(1, $statuses[201] ?? 0, 'exactly one of the eight wins: '.json_encode($statuses));
        $this->assertSame(7, $statuses[409] ?? 0);
        $this->assertNeverOverCollected($order);
        $this->assertSame(1, DB::table('payment')->count());
    }

    public function test_concurrent_double_confirm_captures_once(): void
    {
        $order = $this->billedOrder('9000.0000');
        $id = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '9000.0000', 'approvalCode' => 'DC1'])->assertCreated()->json('payment.id');
        $actors = [[$this->cashierToken, null], [$this->supervisorToken, null]];
        $res = $this->ok(Concurrent::run(6, CollectionWorkers::class, 'call', [$actors, 'POST', "/api/v1/payments/{$id}/confirm", 'dc', []]));

        $this->assertSame([200 => 6], $this->tally($res), json_encode($res));
        $this->assertSame(1, DB::table('receipt')->count());
        $this->assertSame(1, DB::table('payment_collection_decision')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'PaymentConfirmed')->count());
        $this->assertSame('9000.0000', Ledger::paidByOrder([$order])[$order]);
        $this->assertSame('SETTLED', $this->orderStatus($order));
    }

    public function test_confirm_versus_reject_race_yields_exactly_one_decision(): void
    {
        $order = $this->billedOrder('4000.0000');
        $id = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '4000.0000', 'approvalCode' => 'CR1'])->assertCreated()->json('payment.id');
        $res = $this->ok(Concurrent::run(8, CollectionWorkers::class, 'confirmOrReject', [[[$this->cashierToken, null], [$this->supervisorToken, null]], $id]));

        $decisions = DB::table('payment_collection_decision')->where('payment_id', Ids::toBinary($id))->get();
        $this->assertCount(1, $decisions);
        $status = $this->paymentStatus($id);
        if ($decisions[0]->decision === 'CONFIRMED') {
            $this->assertSame('CAPTURED', $status);
            $this->assertSame('4000.0000', Ledger::paidByOrder([$order])[$order]);
            foreach ($res as $i => $r) {
                $this->assertSame($i % 2 === 0 ? 200 : 409, $r[0], "worker {$i}");
            }
        } else {
            $this->assertSame('REJECTED', $status);
            $this->assertSame('0.0000', Ledger::paidByOrder([$order])[$order]);
            foreach ($res as $i => $r) {
                $this->assertSame($i % 2 === 0 ? 409 : 200, $r[0], "worker {$i}");
            }
        }
        $this->assertLessThanOrEqual(1, DB::table('receipt')->count());
    }

    public function test_paystack_webhook_delivered_three_times_at_once_records_once(): void
    {
        PaystackFakes::configure();
        PaystackFakes::http();
        $order = $this->billedOrder('9000.0000');
        $r = $this->collect($order, ['tenderType' => 'PAY_LINK', 'amount' => '9000.0000'])->assertCreated();
        $ref = $r->json('payLink.reference');
        PaystackFakes::remember($ref, '9000.0000');
        $body = PaystackFakes::body($ref);
        $res = $this->ok(Concurrent::run(3, PaymentWorkers::class, 'webhook', [$body, 900000]));

        $this->assertSame([200 => 3], $this->tally($res));
        $this->assertSame(1, count(array_filter($res, fn ($x) => $x[1]['duplicate'] === false)), json_encode($res));
        $this->assertSame('CAPTURED', $this->paymentStatus($r->json('payment.id')));
        $this->assertSame(1, DB::table('receipt')->count());
        $this->assertSame(1, DB::table('payment_collection_decision')->where('mode', 'PROVIDER')->count());
        $this->assertSame(1, DB::table('provider_event')->count());
        $this->assertSame('9000.0000', Ledger::paidByOrder([$order])[$order]);
        $this->assertSame('SETTLED', $this->orderStatus($order));
    }

    public function test_cash_limit_holds_under_concurrent_collections(): void
    {
        $this->setRuleFlush('waiter_cash_in_hand_limit', '5000');
        $orders = [];
        for ($i = 0; $i < 6; $i++) {
            $orders[] = $this->billedOrder('3000.0000');
        }
        // six requests, one per order, all by the same waiter: the staff-row lock serialises the cash-in-hand check
        $bodies = [];
        foreach ($orders as $o) {
            $bodies[] = "/api/v1/orders/{$o}/collections";
        }
        $res = $this->ok(Concurrent::run(6, CollectionWorkers::class, 'callEach', [$this->waiterToken, $this->deviceToken, $bodies, ['tenderType' => 'CASH', 'amount' => '2000.0000']]));
        $this->assertSame([201 => 2, 409 => 4], $this->tally($res));
        $this->assertSame(4000.0, (float) DB::table('cash_in_hand_entry')->where('staff_id', Ids::toBinary($this->waiter->id))->sum('amount'));
        foreach ($res as $r) {
            if ($r[0] === 409) {
                $this->assertSame('cash_limit_exceeded', $r[1]['code']);
            }
        }
    }

    public function test_a_handover_can_be_received_only_once_under_concurrency(): void
    {
        $order = $this->billedOrder('6000.0000');
        $this->collect($order, ['tenderType' => 'CASH', 'amount' => '6000.0000'])->assertCreated();
        $h = $this->postJson('/api/v1/cash-handovers', ['declaredAmount' => '6000.0000'], $this->auth($this->waiterToken))->assertCreated()->json('id');
        $res = $this->ok(Concurrent::run(6, CollectionWorkers::class, 'call', [[[$this->cashierToken, null], [$this->supervisorToken, null]], 'POST', "/api/v1/cash-handovers/{$h}/receive", 'rcv', ['countedAmount' => '6000.0000']]));

        $this->assertSame([200 => 1, 409 => 5], $this->tally($res));
        $this->assertSame(1, DB::table('cash_in_hand_entry')->where('kind', 'HANDOVER')->count());
        $this->assertSame(0.0, (float) DB::table('cash_in_hand_entry')->where('staff_id', Ids::toBinary($this->waiter->id))->sum('amount'));
    }
}
