<?php

namespace Tests\Feature\Payments;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsWorld;
use Tests\Support\TestData;
use Tests\TestCase;

class PaymentTakingTest extends TestCase
{
    use PaymentsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_cash_payment_with_change_settles_the_order_and_issues_a_receipt(): void
    {
        $order = $this->makeOrder('9000.0000');
        $session = $this->openSession();

        $r = $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '9000.0000']],
            [['tenderType' => 'CASH', 'amount' => '9000.0000', 'tendered' => '10000.0000']],
            $session,
        ), $this->auth($this->cashierToken))->assertCreated();

        $r->assertJsonPath('changeDue', '1000.0000')
            ->assertJsonPath('payments.0.status', 'CAPTURED')
            ->assertJsonPath('payments.0.tenderType', 'CASH')
            ->assertJsonPath('payments.0.provider', 'MANUAL')
            ->assertJsonPath('payments.0.amount', '9000.0000')
            ->assertJsonPath('payments.0.tendered', '10000.0000')
            ->assertJsonPath('payments.0.changeGiven', '1000.0000')
            ->assertJsonPath('payments.0.cashSessionId', $session)
            ->assertJsonPath('payments.0.allocations.0.orderId', $order)
            ->assertJsonPath('orders.0.status', 'SETTLED')
            ->assertJsonPath('orders.0.balanceDue', '0.0000');
        $this->assertSame('SETTLED', $this->orderStatus($order));
        $this->assertSame('9000.0000', $this->paidOf($order));

        // ledger side effects in the same transaction: audit + outbox + receipt
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.capture')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OrderSettled')->count(), 'Orders settled the order through OrderSettlementService');
        $ob = DB::table('outbox_event')->where('event_type', 'PaymentCompleted')->first();
        $this->assertNotNull($ob);
        $this->assertSame('9000.0000', json_decode($ob->payload, true)['amount']);
        $this->getJson('/api/v1/receipts/'.$r->json('receiptId'), $this->auth($this->cashierToken, null))->assertOk()
            ->assertJsonPath('changeGiven', '1000.0000')->assertJsonPath('cashierName', 'Cashier1 Tester');
    }

    public function test_split_payment_across_two_tenders_records_one_payment_each_with_a_shared_group(): void
    {
        $a = $this->makeOrder('3000.0000');
        $b = $this->makeOrder('6000.0000');
        $session = $this->openSession();

        $r = $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $a, 'amount' => '3000.0000'], ['orderId' => $b, 'amount' => '6000.0000']],
            [
                ['tenderType' => 'CASH', 'amount' => '4000.0000', 'tendered' => '4000.0000'],
                ['tenderType' => 'POS_TERMINAL', 'amount' => '5000.0000', 'reference' => 'RRN123456789'],
            ],
            $session,
        ), $this->auth($this->cashierToken))->assertCreated();

        $p = $r->json('payments');
        $this->assertCount(2, $p);
        $this->assertSame($p[0]['groupId'], $p[1]['groupId']);
        // first-come allocation: cash 4000 = A 3000 + B 1000 ; POS 5000 = B 5000
        $this->assertSame([['orderId' => $a, 'amount' => '3000.0000'], ['orderId' => $b, 'amount' => '1000.0000']], $p[0]['allocations']);
        $this->assertSame([['orderId' => $b, 'amount' => '5000.0000']], $p[1]['allocations']);
        $this->assertSame('SETTLED', $this->orderStatus($a));
        $this->assertSame('SETTLED', $this->orderStatus($b));
        $this->assertSame('0.0000', $r->json('changeDue'));
    }

    public function test_partial_payment_leaves_the_order_open_with_the_remaining_balance(): void
    {
        $order = $this->makeOrder('10000.0000');
        $session = $this->openSession();
        $r = $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '4000.0000']],
            [['tenderType' => 'CASH', 'amount' => '4000.0000']],
            $session,
        ), $this->auth($this->cashierToken))->assertCreated();
        $r->assertJsonPath('orders.0.status', 'SERVED')->assertJsonPath('orders.0.balanceDue', '6000.0000');
        $this->assertSame('SERVED', $this->orderStatus($order));

        // second instalment settles it
        $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '6000.0000']],
            [['tenderType' => 'TRANSFER', 'amount' => '6000.0000', 'reference' => 'NIP-99887766']],
        ), $this->auth($this->cashierToken))->assertCreated()->assertJsonPath('orders.0.status', 'SETTLED');
        $this->assertSame('SETTLED', $this->orderStatus($order));
    }

    public function test_allocating_more_than_the_balance_is_balance_changed_and_nothing_is_written(): void
    {
        $order = $this->makeOrder('5000.0000');
        $session = $this->openSession();
        $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '6000.0000']],
            [['tenderType' => 'CASH', 'amount' => '6000.0000']],
            $session,
        ), $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'balance_changed')->assertJsonPath('balanceDue', '5000.0000');
        $this->assertSame(0, DB::table('payment')->count());
        $this->assertSame(0, DB::table('receipt')->count());
    }

    public function test_tender_sum_must_equal_allocation_sum(): void
    {
        $order = $this->makeOrder('5000.0000');
        $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '5000.0000']],
            [['tenderType' => 'TRANSFER', 'amount' => '4000.0000', 'reference' => 'X1']],
        ), $this->auth($this->cashierToken))->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
    }

    public function test_cash_tendered_below_amount_and_non_cash_over_tender_are_rejected(): void
    {
        $order = $this->makeOrder('5000.0000');
        $session = $this->openSession();
        $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '5000.0000']],
            [['tenderType' => 'CASH', 'amount' => '5000.0000', 'tendered' => '4000.0000']], $session,
        ), $this->auth($this->cashierToken))->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => '5000.0000']],
            [['tenderType' => 'CARD', 'amount' => '5000.0000', 'tendered' => '6000.0000']],
        ), $this->auth($this->cashierToken))->assertStatus(422);
    }

    public function test_cash_without_an_open_session_is_refused_when_the_facility_requires_one(): void
    {
        $order = $this->makeOrder('1000.0000');
        $body = $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'CASH', 'amount' => '1000.0000']]);
        $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'cash_session_required');

        // ... but a facility that does not require one may take it, and non-cash never needed one
        $this->setRule('require_cash_session', 'false');
        $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken))->assertCreated()->assertJsonPath('payments.0.cashSessionId', null);
    }

    public function test_the_cashiers_own_open_session_is_picked_up_automatically(): void
    {
        $order = $this->makeOrder('1000.0000');
        $session = $this->openSession();
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'CASH', 'amount' => '1000.0000']]), $this->auth($this->cashierToken))
            ->assertCreated()->assertJsonPath('payments.0.cashSessionId', $session);
    }

    public function test_another_cashiers_or_a_closed_session_cannot_be_used(): void
    {
        $order = $this->makeOrder('2000.0000');
        $other = TestData_staff::make($this->t, 'cashier2');
        $theirs = $this->openSession($other);
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'CASH', 'amount' => '1000.0000']], $theirs), $this->auth($this->cashierToken))
            ->assertStatus(403);

        $mine = $this->openSession();
        DB::table('cash_session')->where('id', Ids::toBinary($mine))->update(['status' => 'CLOSED', 'expected_cash' => '5000', 'counted_cash' => '5000', 'variance' => '0', 'closed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'CASH', 'amount' => '1000.0000']], $mine), $this->auth($this->cashierToken))
            ->assertStatus(409)->assertJsonPath('code', 'cash_session_required');
    }

    public function test_pos_and_transfer_require_a_reference_and_a_reference_cannot_back_two_payments(): void
    {
        $a = $this->makeOrder('1000.0000');
        $b = $this->makeOrder('1000.0000');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $a, 'amount' => '1000.0000']], [['tenderType' => 'POS_TERMINAL', 'amount' => '1000.0000']]), $this->auth($this->cashierToken))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $tender = [['tenderType' => 'POS_TERMINAL', 'amount' => '1000.0000', 'reference' => 'RRN-1']];
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $a, 'amount' => '1000.0000']], $tender), $this->auth($this->cashierToken))->assertCreated();
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $b, 'amount' => '1000.0000']], $tender), $this->auth($this->cashierToken))
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_reference');
        $this->assertSame('SERVED', $this->orderStatus($b));
    }

    public function test_order_states_and_payment_timing_rule_gate_payment(): void
    {
        $voided = $this->makeOrder('1000.0000', 'VOIDED');
        $pending = $this->makeOrder('1000.0000', 'PENDING_APPROVAL');
        foreach ([$voided, $pending] as $o) {
            $st = $this->orderStatus($o);
            $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $o, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'R'.$st]]), $this->auth($this->cashierToken))
                ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid')->assertJsonPath('status', $st);
        }

        // default (pay after service): a SENT order cannot be settled yet, a SERVED one can
        $sent = $this->makeOrder('1000.0000', 'SENT');
        $served = $this->makeOrder('1000.0000', 'SERVED');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $sent, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'S1']]), $this->auth($this->cashierToken))
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid')->assertJsonPath('paymentTiming', 'PAY_AFTER_SERVICE');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $served, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'S2']]), $this->auth($this->cashierToken))
            ->assertCreated();

        // pay-first facility: a SENT order is payable; fully paid it is not SETTLED until Orders says so (only served orders settle)
        $this->setRule('payment_timing', 'PAY_FIRST');
        $r = $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $sent, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'S3']]), $this->auth($this->cashierToken))
            ->assertCreated();
        $r->assertJsonPath('orders.0.status', 'SENT')->assertJsonPath('orders.0.balanceDue', '0.0000');
        $this->assertSame('1000.0000', DB::table('order')->where('id', Ids::toBinary($sent))->value('amount_paid') ? bcadd((string) DB::table('order')->where('id', Ids::toBinary($sent))->value('amount_paid'), '0', 4) : '');

        // pay-on-exit is Orders' alias of open-tab: SERVED orders only
        $this->setRule('payment_timing', 'PAY_ON_EXIT');
        $sent2 = $this->makeOrder('1000.0000', 'SENT');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $sent2, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'S4']]), $this->auth($this->cashierToken))
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');
    }

    public function test_order_of_another_facility_is_a_facility_mismatch(): void
    {
        $other = TestData::facility($this->t, 'spa')->id;
        $order = $this->makeOrder('1000.0000', 'SERVED', null, $other);
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'F1']]), $this->auth($this->cashierToken))
            ->assertStatus(409)->assertJsonPath('code', 'facility_mismatch');
    }

    public function test_permissions_are_enforced_per_permission_not_per_role_name(): void
    {
        $order = $this->makeOrder('1000.0000');
        $waiter = TestData::staff($this->t, 'waiter1');
        TestData::assign($waiter, 'WAIT_STAFF', 'SITE');
        $token = $this->loginToken('waiter1');
        $body = $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'W1']]);
        $this->postJson('/api/v1/payments', $body, $this->auth($token))->assertForbidden()->assertJsonPath('code', 'permission_denied');

        // a custom role with a made-up name and only payment.take works; without payment.split it cannot split
        $role = TestData::customRole('TILL_TEMP', 'Weekend till', ['payment.take']);
        $temp = TestData::staff($this->t, 'temp1');
        TestData::assignRole($temp, $role, 'SITE');
        $tt = $this->loginToken('temp1');
        $split = $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [
            ['tenderType' => 'TRANSFER', 'amount' => '400.0000', 'reference' => 'W2'], ['tenderType' => 'TRANSFER', 'amount' => '600.0000', 'reference' => 'W3'],
        ]);
        $this->postJson('/api/v1/payments', $split, $this->auth($tt))->assertForbidden()->assertJsonPath('permission', 'payment.split');
        $this->postJson('/api/v1/payments', $body, $this->auth($tt))->assertCreated();
    }

    public function test_missing_idempotency_key_and_replay(): void
    {
        $order = $this->makeOrder('1000.0000');
        $body = $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'I1']]);
        $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken, null))->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');

        $h = $this->auth($this->cashierToken, 'same-key-1');
        $first = $this->postJson('/api/v1/payments', $body, $h)->assertCreated();
        $second = $this->postJson('/api/v1/payments', $body, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('payments.0.id'), $second->json('payments.0.id'));
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame('1000.0000', $this->paidOf($order));
    }

    public function test_client_supplied_tender_id_replays_and_conflicts_on_a_different_body(): void
    {
        $order = $this->makeOrder('2000.0000');
        $id = Ids::uuid7();
        $body = $this->payBody([['orderId' => $order, 'amount' => '2000.0000']], [['id' => $id, 'tenderType' => 'TRANSFER', 'amount' => '2000.0000', 'reference' => 'C1']]);

        $first = $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken))->assertCreated();
        $this->assertSame($id, $first->json('payments.0.id'));
        // a retry from the offline queue with a NEW idempotency key but the same tender id => original result, no duplicate
        $again = $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('receiptId'), $again->json('receiptId'));
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame('2000.0000', $this->paidOf($order));

        // same id, different body => 409 concurrency_conflict
        $other = $this->makeOrder('900.0000');
        $bad = $this->payBody([['orderId' => $other, 'amount' => '900.0000']], [['id' => $id, 'tenderType' => 'TRANSFER', 'amount' => '900.0000', 'reference' => 'C2']]);
        $this->postJson('/api/v1/payments', $bad, $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
        // non-v7 id => 422
        $body['tenders'][0]['id'] = '11111111-1111-4111-8111-111111111111';
        $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_tab_settlement_pays_every_open_order_with_change_and_closes_the_tab(): void
    {
        $tab = $this->makeTab();
        $a = $this->makeOrder('4000.0000', 'SERVED', $tab);
        $b = $this->makeOrder('5000.0000', 'SERVED', $tab);
        $void = $this->makeOrder('700.0000', 'VOIDED', $tab);
        $session = $this->openSession();

        $r = $this->postJson("/api/v1/tabs/{$tab}/settle", [
            'tenders' => [['tenderType' => 'CASH', 'amount' => '9000.0000', 'tendered' => '10000.0000']], 'cashSessionId' => $session,
        ], $this->auth($this->cashierToken))->assertOk();
        $r->assertJsonPath('changeDue', '1000.0000')->assertJsonPath('payments.0.amount', '9000.0000');
        $this->assertSame('SETTLED', $this->orderStatus($a));
        $this->assertSame('SETTLED', $this->orderStatus($b));
        $this->assertSame('VOIDED', $this->orderStatus($void));
        $this->assertSame('SETTLED', DB::table('tab')->where('id', Ids::toBinary($tab))->value('status'));

        // settle again => nothing owed
        $this->postJson("/api/v1/tabs/{$tab}/settle", ['tenders' => [['tenderType' => 'CASH', 'amount' => '9000.0000']], 'cashSessionId' => $session], $this->auth($this->cashierToken))
            ->assertStatus(409)->assertJsonPath('code', 'balance_changed');
    }

    public function test_tab_settlement_over_tender_is_cash_only_and_under_tender_is_rejected(): void
    {
        $tab = $this->makeTab();
        $this->makeOrder('4000.0000', 'SERVED', $tab);
        $this->makeOrder('5000.0000', 'SERVED', $tab);
        $session = $this->openSession();
        $this->postJson("/api/v1/tabs/{$tab}/settle", ['tenders' => [['tenderType' => 'CASH', 'amount' => '8000.0000']], 'cashSessionId' => $session], $this->auth($this->cashierToken))
            ->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->postJson("/api/v1/tabs/{$tab}/settle", ['tenders' => [['tenderType' => 'TRANSFER', 'amount' => '10000.0000', 'reference' => 'T-1']]], $this->auth($this->cashierToken))
            ->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        // split: transfer 6000 + cash 4000 (tendered 5000) for a 9000 tab => cash applied 3000, change 2000
        $r = $this->postJson("/api/v1/tabs/{$tab}/settle", ['tenders' => [
            ['tenderType' => 'TRANSFER', 'amount' => '6000.0000', 'reference' => 'T-2'], ['tenderType' => 'CASH', 'amount' => '4000.0000', 'tendered' => '5000.0000'],
        ], 'cashSessionId' => $session], $this->auth($this->cashierToken))->assertOk();
        $this->assertSame('3000.0000', $r->json('payments.1.amount'));
        $this->assertSame('2000.0000', $r->json('payments.1.changeGiven'));
        $this->assertSame('2000.0000', $r->json('changeDue'));
    }

    public function test_payment_list_and_get_respect_scope(): void
    {
        $order = $this->makeOrder('1000.0000');
        $p = $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '1000.0000']], [['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'reference' => 'L1']]), $this->auth($this->cashierToken))->assertCreated()->json('payments.0');
        $this->getJson('/api/v1/payments/'.$p['id'], $this->auth($this->cashierToken, null))->assertOk()->assertJsonPath('id', $p['id']);
        $this->getJson('/api/v1/payments?filter[facilityId]='.$this->facility.'&filter[orderId]='.$order, $this->auth($this->supervisorToken, null))->assertOk()
            ->assertJsonPath('items.0.id', $p['id'])->assertJsonPath('nextCursor', null);
        // someone without payment.view cannot read another cashier's payment
        $waiter = TestData::staff($this->t, 'waiter2');
        TestData::assign($waiter, 'WAIT_STAFF', 'SITE');
        $this->getJson('/api/v1/payments/'.$p['id'], $this->auth($this->loginToken('waiter2'), null))->assertForbidden();
    }
}

/** tiny helper so the test above reads well */
final class TestData_staff
{
    public static function make(array $t, string $user): object
    {
        $s = TestData::staff($t, $user);
        TestData::assign($s, 'CASHIER', 'SITE');

        return $s;
    }
}
