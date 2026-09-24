<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Broadcast\PaymentConfirmed;
use App\Domain\Payments\Contracts\PaymentTerminalAdapter;
use App\Domain\Payments\Provider\Terminal\TerminalCharge;
use App\Domain\Payments\Services\CollectionService;
use App\Domain\Payments\Services\TerminalAdapterRegistry;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\CollectionWorld;
use Tests\Support\PaystackFakes;
use Tests\Support\TestData;
use Tests\TestCase;

/** Bill -> collect -> confirm/reject/expire (docs/WAITER_COLLECTION.md). */
class WaiterCollectionTest extends TestCase
{
    use CollectionWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCollectionWorld(cashHolding: true);
        $this->openSession($this->cashier);
    }

    // ---- bill -----------------------------------------------------------------------------------------------------------

    public function test_bill_freezes_the_order_returns_a_prebill_and_reprints_are_counted_and_audited(): void
    {
        $order = $this->makeOrder('9000.0000');
        $r = $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->waiterToken))->assertOk();
        $bill = $r->json('bill');
        $this->assertSame('PRE_BILL', $bill['kind']);
        $this->assertFalse($r->json('reprint'));
        $this->assertSame('BILL_PRINTED', $r->json('order.billState'));
        $this->assertSame('SERVED', $r->json('order.status'), 'status is untouched');
        $this->assertTrue($r->json('order.awaitingPayment'));
        $this->assertSame(1, $r->json('order.billPrintCount'));
        $this->assertSame('9000.0000', $bill['total']);
        $this->assertSame([], $bill['taxLines'], 'VAT off => no tax lines');
        $this->assertStringContainsString('NOT A RECEIPT', $bill['printLines'][0]);
        $this->assertStringContainsString('NOT A RECEIPT', end($bill['printLines']));
        $this->assertNotEmpty($bill['waiter']['name']);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'order.bill.print')->count());

        $again = $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->cashierToken))->assertOk();
        $this->assertTrue($again->json('reprint'));
        $this->assertSame(2, $again->json('order.billPrintCount'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'order.bill.reprint')->count());

        // frozen: line changes, discounts and voids are refused
        $ver = (int) DB::table('order')->where('id', Ids::toBinary($order))->value('row_version');
        $this->postJson("/api/v1/orders/{$order}/lines", ['productId' => Ids::uuid7(), 'quantity' => 1], $this->auth($this->waiterToken) + ['If-Match' => "\"v{$ver}\""])->assertStatus(409)->assertJsonPath('code', 'order_billed');
        $ver = (int) DB::table('order')->where('id', Ids::toBinary($order))->value('row_version');
        $this->postJson("/api/v1/orders/{$order}/void", ['reason' => 'changed my mind'], $this->auth($this->supervisorToken) + ['If-Match' => "\"v{$ver}\""])->assertStatus(409)->assertJsonPath('code', 'order_billed');
        // a permission-less caller cannot print
        $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->loginToken('super1')))->assertOk(); // supervisor holds bill.print
        $nobody = $this->roleToken('nobody', ['order.view']);
        $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($nobody))->assertStatus(403)->assertJsonPath('code', 'permission_denied');
    }

    public function test_vat_lines_appear_only_when_the_organization_is_vat_registered(): void
    {
        $this->setVat(true);
        $order = $this->makeOrder('10750.0000', 'SERVED', null, null, '750.0000');
        $r = $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->waiterToken))->assertOk();
        $this->assertCount(1, $r->json('bill.taxLines'));
        $this->assertSame('750.0000', $r->json('bill.taxLines.0.amount'));
    }

    public function test_cancel_bill_needs_approval_for_a_waiter_but_a_supervisor_reopens_at_once_and_reprint_then_needs_a_supervisor(): void
    {
        $order = $this->billedOrder();
        $r = $this->postJson("/api/v1/orders/{$order}/bill/cancel", ['reason' => 'customer wants to add drinks'], $this->auth($this->waiterToken))->assertStatus(202);
        $this->assertSame('PENDING_APPROVAL', $r->json('status'));
        $this->assertSame('BILL_PRINTED', $r->json('order.billState'));
        $approval = $r->json('approval.id');
        $this->postJson("/api/v1/approvals/{$approval}/decision", ['decision' => 'APPROVE'], $this->auth($this->supervisorToken))->assertOk();
        $this->assertNull(DB::table('order')->where('id', Ids::toBinary($order))->value('bill_printed_at'));

        // reopened: the waiter cannot simply print again (rule default true), a supervisor can
        $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->waiterToken))->assertStatus(403)->assertJsonPath('code', 'supervisor_required');
        $this->setRuleFlush('pre_bill_requires_supervisor_if_reopened', 'false');
        $this->postJson("/api/v1/orders/{$order}/bill", [], $this->auth($this->waiterToken))->assertOk()->assertJsonPath('order.billReopenCount', 1);
        $this->postJson("/api/v1/orders/{$order}/bill/cancel", ['reason' => 'again please'], $this->auth($this->supervisorToken))->assertOk()->assertJsonPath('billState', 'OPEN');
    }

    public function test_bill_cannot_be_cancelled_while_money_is_pending(): void
    {
        $order = $this->billedOrder();
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'approvalCode' => 'A1'])->assertCreated();
        $this->postJson("/api/v1/orders/{$order}/bill/cancel", ['reason' => 'nope nope'], $this->auth($this->supervisorToken))->assertStatus(409)->assertJsonPath('code', 'collections_pending');
        $ver = (int) DB::table('order')->where('id', Ids::toBinary($order))->value('row_version');
        $this->postJson("/api/v1/orders/{$order}/void", ['reason' => 'nope nope'], $this->auth($this->supervisorToken) + ['If-Match' => "\"v{$ver}\""])->assertStatus(409);
    }

    // ---- collection -----------------------------------------------------------------------------------------------------

    public function test_card_terminal_collection_is_pending_and_never_settles_the_order(): void
    {
        $order = $this->billedOrder('9000.0000');
        $r = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '9000.0000', 'approvalCode' => 'APP123', 'slipReference' => 'RRN-9', 'last4' => '4242'])->assertCreated();
        $id = $r->json('payment.id');
        $this->assertSame('PENDING_CONFIRMATION', $r->json('payment.status'));
        $this->assertSame('CARD_TERMINAL', $r->json('payment.collection.tender'));
        $this->assertSame('POS_TERMINAL', $r->json('payment.tenderType'), 'ledger tender type');
        $this->assertSame($this->waiter->id, $r->json('payment.takenByStaffId'));
        $this->assertSame($this->deviceId, $r->json('payment.collection.collectedDeviceId'));
        $this->assertSame('9000.0000', $r->json('order.balanceDue'), 'captured balance untouched');
        $this->assertSame('9000.0000', $r->json('order.pendingCollected'));
        $this->assertSame('0.0000', $r->json('order.collectable'));
        $this->assertNull($r->json('payment.receiptId'));
        $this->assertSame('SERVED', $this->orderStatus($order));
        $this->assertSame('0.0000', $this->paidOf($order));
        $this->assertSame(0, DB::table('receipt')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.collect')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'PaymentCollected')->count());
        $this->assertSame('PENDING_CONFIRMATION', $this->paymentStatus($id));
    }

    public function test_preconditions_bill_device_rule_and_amounts(): void
    {
        $unbilled = $this->makeOrder('5000.0000');
        $this->collect($unbilled, ['tenderType' => 'CARD_TERMINAL', 'amount' => '5000.0000'])->assertStatus(409)->assertJsonPath('code', 'order_not_billed');

        $order = $this->billedOrder('5000.0000');
        // someone else's tablet / no device
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000'], $this->waiterToken, $this->device2Token)->assertStatus(403)->assertJsonPath('code', 'device_not_checked_out');
        $this->postJson("/api/v1/orders/{$order}/collections", ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000'], $this->auth($this->waiterToken))->assertStatus(403)->assertJsonPath('code', 'device_not_checked_out');
        // over-collection
        $r = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '5000.0001'])->assertStatus(409);
        $this->assertSame('over_collection', $r->json('code'));
        $this->assertSame('5000.0000', $r->json('collectable'));
        // validation
        $this->collect($order, ['tenderType' => 'BITCOIN', 'amount' => '1.0000'])->assertStatus(422);
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '10.12345'])->assertStatus(422);
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '100.0000', 'tendered' => '200.0000'])->assertStatus(422);
        // a cashier (no payment.collect) cannot use the waiter endpoint
        $this->postJson("/api/v1/orders/{$order}/collections", ['tenderType' => 'CARD_TERMINAL', 'amount' => '100.0000'], $this->auth($this->cashierToken))->assertStatus(403);
        // rule off
        $this->setRuleFlush('waiter_collection_enabled', 'false');
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '100.0000'])->assertStatus(409)->assertJsonPath('code', 'collection_disabled');
        $this->assertSame(0, DB::table('payment')->count());
    }

    public function test_duplicate_client_id_replays_once_and_a_different_body_conflicts(): void
    {
        $order = $this->billedOrder('9000.0000');
        $id = Ids::uuid7();
        $body = ['id' => $id, 'tenderType' => 'CARD_TERMINAL', 'amount' => '4000.0000', 'approvalCode' => 'X1'];
        $this->collect($order, $body)->assertCreated();
        $again = $this->collect($order, $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true'); // fresh Idempotency-Key, same client id
        $this->assertSame($id, $again->json('payment.id'));
        $this->assertSame(1, DB::table('payment')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.collect')->count());
        $this->collect($order, ['amount' => '4001.0000'] + $body)->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
        // same slip reference on another collection
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'approvalCode' => 'X1'])->assertStatus(409)->assertJsonPath('code', 'duplicate_reference');
        // same Idempotency-Key replays the stored response
        $k = 'same-key-'.Ids::uuid7();
        $b2 = ['tenderType' => 'CARD_TERMINAL', 'amount' => '500.0000', 'approvalCode' => 'Y2'];
        $this->collect($order, $b2, null, null, $k)->assertCreated();
        $this->collect($order, $b2, null, null, $k)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, DB::table('payment')->count());
    }

    // ---- cash holding ---------------------------------------------------------------------------------------------------

    public function test_cash_is_refused_when_cash_holding_is_not_allowed_but_card_and_transfer_still_work(): void
    {
        $this->setRuleFlush('waiter_cash_holding', 'false');
        $order = $this->billedOrder('9000.0000');
        $r = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '1000.0000'])->assertStatus(403);
        $this->assertSame('cash_holding_not_allowed', $r->json('code'));
        $this->assertStringContainsString('cashier', $r->json('detail'));
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'approvalCode' => 'A9'])->assertCreated();
        $this->collect($order, ['tenderType' => 'TRANSFER', 'amount' => '1000.0000', 'bankReference' => 'NIP-1'])->assertCreated();
        $this->assertSame(0, DB::table('cash_in_hand_entry')->count());
        $this->getJson("/api/v1/staff/{$this->waiter->id}/collection-policy", $this->auth($this->waiterToken, null))->assertOk()
            ->assertJsonPath('cashHolding.allowed', false)->assertJsonPath('cashHolding.source', 'facility')->assertJsonPath('allowedTenders', ['CARD_TERMINAL', 'TRANSFER', 'PAY_LINK']);
    }

    public function test_staff_override_allow_and_deny_beat_the_facility_rule_and_the_limit_is_enforced(): void
    {
        $order = $this->billedOrder('20000.0000');
        // only staff.manage holders may change a policy
        $this->patchJson("/api/v1/staff/{$this->waiter->id}/collection-policy", ['cashHolding' => 'ALLOW'], $this->auth($this->waiterToken))->assertStatus(403);
        // facility allows; staff DENY wins
        $owner = $this->ownerToken();
        $this->patchJson("/api/v1/staff/{$this->waiter->id}/collection-policy", ['cashHolding' => 'DENY'], $this->auth($owner))->assertOk()
            ->assertJsonPath('cashHolding.allowed', false)->assertJsonPath('cashHolding.source', 'staff');
        $this->collect($order, ['tenderType' => 'CASH', 'amount' => '1000.0000'])->assertStatus(403)->assertJsonPath('code', 'cash_holding_not_allowed');
        // facility forbids; staff ALLOW with a personal limit wins
        $this->setRuleFlush('waiter_cash_holding', 'false');
        $this->patchJson("/api/v1/staff/{$this->waiter->id}/collection-policy", ['cashHolding' => 'ALLOW', 'cashLimit' => '5000.0000'], $this->auth($owner))->assertOk()
            ->assertJsonPath('cashHolding.allowed', true)->assertJsonPath('cashHolding.limit', '5000.0000')->assertJsonPath('cashHolding.limitSource', 'staff');
        $this->collect($order, ['tenderType' => 'CASH', 'amount' => '3000.0000'])->assertCreated();
        $r = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '2500.0000'])->assertStatus(409);
        $this->assertSame('cash_limit_exceeded', $r->json('code'));
        $this->assertSame('3000.0000', $r->json('cashInHand'));
        $this->collect($order, ['tenderType' => 'CASH', 'amount' => '2000.0000'])->assertCreated(); // exactly at the limit
        $this->assertSame('5000.0000', $this->getJson("/api/v1/staff/{$this->waiter->id}/cash-in-hand", $this->auth($this->waiterToken, null))->assertOk()->json('cashInHand'));
        $this->assertSame(2, DB::table('audit_log')->where('action', 'staff.collection_policy.update')->count());
        $this->assertSame(2, DB::table('outbox_event')->where('event_type', 'StaffCollectionPolicyChanged')->count());
        // INHERIT again + facility limit
        $this->patchJson("/api/v1/staff/{$this->waiter->id}/collection-policy", ['cashHolding' => 'INHERIT', 'cashLimit' => null], $this->auth($owner))->assertOk()->assertJsonPath('cashHolding.source', 'facility');
    }

    public function test_cash_collection_raises_the_waiters_cash_in_hand_and_nothing_is_paid(): void
    {
        $order = $this->billedOrder('9000.0000');
        $r = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '9000.0000', 'tendered' => '10000.0000'])->assertCreated();
        $this->assertSame('PENDING_CONFIRMATION', $r->json('payment.status'));
        $this->assertSame('1000.0000', $r->json('payment.changeGiven'));
        $this->assertSame('9000.0000', app(CollectionService::class)->cashInHand($this->waiter->id));
        $this->assertSame('0.0000', $this->paidOf($order));
        $this->assertNull($this->paymentRow($r->json('payment.id'))->cash_session_id);
    }

    // ---- confirm / reject -----------------------------------------------------------------------------------------------

    public function test_confirm_captures_settles_the_order_issues_a_receipt_and_is_idempotent(): void
    {
        $order = $this->billedOrder('9000.0000');
        $id = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '9000.0000'])->assertCreated()->json('payment.id');

        // the collector cannot confirm (and has no permission anyway); a Manager-named role lacking payment.confirm is denied too
        $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($this->waiterToken))->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $namedManager = $this->roleToken('mgrnamed', ['payment.view', 'order.view', 'report.view'], 'Manager'); // permissions decide, never the role NAME
        $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($namedManager))->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'payment.confirm');

        $r = $this->postJson("/api/v1/payments/{$id}/confirm", ['matchedReference' => 'COUNTED-OK'], $this->auth($this->cashierToken))->assertOk();
        $this->assertSame('CAPTURED', $r->json('payment.status'));
        $this->assertNotNull($r->json('receiptId'));
        $this->assertSame('CONFIRMED', $r->json('payment.collection.decision'));
        $this->assertSame('MANUAL', $r->json('payment.collection.confirmationMode'));
        $this->assertSame($this->cashier->id, $r->json('payment.collection.decidedByStaffId'));
        $this->assertSame('SETTLED', $this->orderStatus($order));
        $this->assertSame('9000.0000', $this->paidOf($order));
        $this->assertNotNull($this->paymentRow($id)->cash_session_id, 'cash booked into the confirming cashier\'s session');
        $this->assertSame(1, DB::table('receipt')->count());

        // double confirm: same body, nothing new
        $again = $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($this->supervisorToken))->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($id, $again->json('payment.id'));
        $this->assertSame(1, DB::table('receipt')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.confirm')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'PaymentConfirmed')->count());
        $this->assertSame(1, DB::table('payment_collection_decision')->count());

        // reject after confirm is refused
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'too late now'], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
        $this->assertSame('CAPTURED', $this->paymentStatus($id));
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_the_collector_cannot_confirm_their_own_collection_even_with_the_permission(): void
    {
        $role = TestData::customRole('COLLECT_CONFIRM', 'Waiter+Confirm', ['payment.collect', 'payment.confirm', 'bill.print', 'order.view']);
        $w = TestData::staff($this->t, 'wc1');
        TestData::assignRole($w, $role);
        $tok = $this->loginToken('wc1');
        [, $dev] = $this->makeTablet('tab-wc', $w);
        $order = $this->billedOrder('3000.0000');
        $id = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '3000.0000', 'approvalCode' => 'Q1'], $tok, $dev)->assertCreated()->json('payment.id');
        $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($tok))->assertStatus(403)->assertJsonPath('code', 'self_confirmation_forbidden');
        $this->assertSame('PENDING_CONFIRMATION', $this->paymentStatus($id));
    }

    public function test_reject_frees_the_balance_audits_alerts_and_a_rejected_payment_cannot_be_confirmed(): void
    {
        $order = $this->billedOrder('9000.0000');
        $id = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '9000.0000', 'approvalCode' => 'BAD1'])->assertCreated()->json('payment.id');
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'slip does not match'], $this->auth($this->waiterToken))->assertStatus(403);
        $r = $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'slip does not match'], $this->auth($this->cashierToken))->assertOk();
        $this->assertSame('REJECTED', $r->json('status'));
        $this->assertSame('REJECTED', $r->json('collection.decision'));
        $this->assertSame('slip does not match', $r->json('collection.decisionReason'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.collection.rejected')->count());
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'payment.collection_rejected')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'PaymentRejected')->count());
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'slip does not match'], $this->auth($this->cashierToken))->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
        // the balance is collectable again and the same slip reference may be re-used after a rejection
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '9000.0000', 'approvalCode' => 'BAD1'])->assertCreated();
    }

    public function test_cash_confirm_needs_an_open_cash_session(): void
    {
        DB::table('cash_session')->update(['status' => 'CLOSED', 'closed_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'counted_cash' => '5000.0000', 'expected_cash' => '5000.0000', 'variance' => '0.0000']);
        $order = $this->billedOrder('2000.0000');
        $id = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '2000.0000'])->assertCreated()->json('payment.id');
        $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'cash_session_required');
        $this->assertSame('PENDING_CONFIRMATION', $this->paymentStatus($id));
    }

    public function test_cashier_direct_payment_cannot_take_what_a_waiter_has_pending(): void
    {
        $order = $this->billedOrder('9000.0000');
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '6000.0000', 'approvalCode' => 'P1'])->assertCreated();
        $session = DB::table('cash_session')->first();
        $body = $this->payBody([['orderId' => $order, 'amount' => '9000.0000']], [['tenderType' => 'CASH', 'amount' => '9000.0000']], Ids::fromBinary($session->id));
        $this->postJson('/api/v1/payments', $body, $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'pending_collection_exists');
        $ok = $this->payBody([['orderId' => $order, 'amount' => '3000.0000']], [['tenderType' => 'CASH', 'amount' => '3000.0000']], Ids::fromBinary($session->id));
        $this->postJson('/api/v1/payments', $ok, $this->auth($this->cashierToken))->assertCreated();
    }

    public function test_a_tender_removed_from_collection_requires_confirmation_is_captured_at_once_only_for_holders_of_payment_take(): void
    {
        $this->setRuleFlush('collection_requires_confirmation', 'CASH,TRANSFER'); // CARD_TERMINAL no longer needs confirmation
        $order = $this->billedOrder('4000.0000');
        // a waiter (no payment.take) still only creates a pending collection
        $r = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'approvalCode' => 'R1'])->assertCreated();
        $this->assertSame('PENDING_CONFIRMATION', $r->json('payment.status'));
    }

    // ---- listing --------------------------------------------------------------------------------------------------------

    public function test_pending_list_filters_and_scoping(): void
    {
        $o1 = $this->billedOrder('1000.0000');
        $o2 = $this->billedOrder('2000.0000');
        $a = $this->collect($o1, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'approvalCode' => 'L1'])->assertCreated()->json('payment.id');
        $this->collect($o2, ['tenderType' => 'TRANSFER', 'amount' => '2000.0000', 'bankReference' => 'L2'], $this->waiter2Token, $this->device2Token)->assertCreated();
        $q = "status=PENDING_CONFIRMATION&facilityId={$this->facility}";
        $all = $this->getJson("/api/v1/payments?{$q}", $this->auth($this->cashierToken, null))->assertOk();
        $this->assertCount(2, $all->json('items'));
        $mine = $this->getJson("/api/v1/payments?{$q}&collectedBy={$this->waiter->id}", $this->auth($this->cashierToken, null))->assertOk();
        $this->assertSame([$a], array_column($mine->json('items'), 'id'));
        // a waiter sees only their own collections and cannot browse the facility list
        $own = $this->getJson("/api/v1/payments?status=PENDING_CONFIRMATION&facilityId={$this->facility}&collectedBy={$this->waiter->id}", $this->auth($this->waiterToken, null))->assertOk();
        $this->assertSame([$a], array_column($own->json('items'), 'id'));
        $this->getJson("/api/v1/payments?{$q}", $this->auth($this->waiterToken, null))->assertStatus(403);
        $this->assertSame([$a], array_column($this->getJson('/api/v1/payments?status=PENDING_CONFIRMATION', $this->auth($this->waiterToken, null))->assertOk()->json('items'), 'id'));
        // GET /payments/{id} is visible to the collector
        $this->getJson("/api/v1/payments/{$a}", $this->auth($this->waiterToken, null))->assertOk()->assertJsonPath('collection.tender', 'CARD_TERMINAL');
        $this->getJson("/api/v1/payments/{$a}", $this->auth($this->waiter2Token, null))->assertStatus(403);
    }

    // ---- expiry ---------------------------------------------------------------------------------------------------------

    public function test_stale_collections_expire_with_alerts_and_cash_stays_in_hand(): void
    {
        $this->setRuleFlush('pending_collection_expiry_minutes', '10');
        $order = $this->billedOrder('8000.0000');
        $cash = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '3000.0000'])->assertCreated()->json('payment.id');
        $card = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '2000.0000', 'approvalCode' => 'E1'])->assertCreated();
        $this->assertEqualsWithDelta(10 * 60, strtotime($card->json('payment.collection.expiresAt')) - time(), 15);

        Artisan::call('r007:payments:expire-pending-collections');
        $this->assertSame('PENDING_CONFIRMATION', $this->paymentStatus($cash), 'not stale yet');

        $this->travel(11)->minutes();
        $this->assertSame(0, Artisan::call('r007:payments:expire-pending-collections'));
        $this->assertSame('EXPIRED', $this->paymentStatus($cash));
        $this->assertSame(2, DB::table('security_event')->where('event_type', 'payment.collection_expired')->count());
        $this->assertSame(2, DB::table('payment_collection_decision')->where('decision', 'EXPIRED')->count());
        $this->assertSame('3000.0000', app(CollectionService::class)->cashInHand($this->waiter->id), 'the waiter stays accountable for the cash');
        // the balance is free again; an expired payment can no longer be confirmed
        $this->postJson("/api/v1/payments/{$cash}/confirm", [], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
        $r = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '8000.0000', 'approvalCode' => 'E2'])->assertCreated();
        $this->assertSame('8000.0000', $r->json('order.pendingCollected'));
        // running again does nothing twice
        Artisan::call('r007:payments:expire-pending-collections');
        $this->assertSame(2, DB::table('security_event')->where('event_type', 'payment.collection_expired')->count());
    }

    // ---- Paystack auto-confirm ------------------------------------------------------------------------------------------

    private function webhook(string $body)
    {
        return $this->call('POST', '/api/v1/payments/webhooks/paystack', [], [], [], $this->transformHeadersToServerVars(['X-Paystack-Signature' => PaystackFakes::sign($body), 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body);
    }

    public function test_pay_link_is_confirmed_by_the_provider_never_by_hand_and_pushes_confirmed_to_the_waiter_device(): void
    {
        PaystackFakes::configure();
        PaystackFakes::http();
        $order = $this->billedOrder('9000.0000');
        $r = $this->collect($order, ['tenderType' => 'PAY_LINK', 'amount' => '9000.0000', 'customerEmail' => 'guest@example.test'])->assertCreated();
        $id = $r->json('payment.id');
        $ref = $r->json('payLink.reference');
        PaystackFakes::remember($ref, '9000.0000');
        $this->assertSame('AUTHORIZING', $r->json('payment.status'));
        $this->assertStringStartsWith('https://checkout.paystack.com/', $r->json('payLink.authorizationUrl'));
        $this->assertTrue($r->json('payment.collection.autoConfirm'));
        $this->assertSame('9000.0000', $r->json('order.pendingCollected'));

        // nobody can confirm it by hand
        $this->postJson("/api/v1/payments/{$id}/confirm", [], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'auto_confirm_only');
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'trying to reject'], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'auto_confirm_only');

        Event::fake([PaymentConfirmed::class]);
        $body = PaystackFakes::body($ref);
        $this->webhook($body)->assertOk()->assertJsonPath('duplicate', false);
        $this->webhook($body)->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook($body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('CAPTURED', $this->paymentStatus($id));
        $this->assertSame('SETTLED', $this->orderStatus($order));
        $this->assertSame(1, DB::table('receipt')->count());
        $this->assertSame(1, DB::table('payment_collection_decision')->where('mode', 'PROVIDER')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.collection.auto_confirm')->count());
        $this->assertSame('9000.0000', $this->paidOf($order));
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
        Event::assertDispatched(PaymentConfirmed::class, fn ($e) => in_array('device.'.$this->deviceId, $e->channels, true) && $e->data['mode'] === 'PROVIDER');
    }

    public function test_paystack_transfer_returns_a_dynamic_account_and_unpaid_links_can_be_cancelled(): void
    {
        PaystackFakes::configure();
        PaystackFakes::http();
        $order = $this->billedOrder('5000.0000');
        $r = $this->collect($order, ['tenderType' => 'TRANSFER', 'channel' => 'PAYSTACK', 'amount' => '5000.0000'])->assertCreated();
        $this->assertSame('AUTHORIZING', $r->json('payment.status'));
        $this->assertSame('9912345678', $r->json('transferAccount.accountNumber'));
        $this->assertSame('TRANSFER', $r->json('payment.tenderType'));
        $id = $r->json('payment.id');
        PaystackFakes::remember(DB::table('payment')->where('id', Ids::toBinary($id))->value('provider_reference'), '5000.0000');
        PaystackFakes::http('abandoned');
        $this->postJson("/api/v1/payments/{$id}/cancel", ['reason' => 'customer will pay cash'], $this->auth($this->waiterToken))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '5000.0000', 'approvalCode' => 'C1'])->assertCreated();
    }

    // ---- terminals ------------------------------------------------------------------------------------------------------

    public function test_terminal_registry_crud_needs_device_manage_and_terminal_is_recorded_on_the_collection(): void
    {
        $it = $this->roleToken('itadmin', ['device.manage']);
        $r = $this->postJson('/api/v1/payment-terminals', ['facilityId' => $this->facility, 'label' => 'Bank POS 1', 'serial' => 'SN-1'], $this->auth($it))->assertCreated();
        $tid = $r->json('id');
        $this->assertSame('MANUAL_BANK', $r->json('provider'));
        $this->postJson('/api/v1/payment-terminals', ['facilityId' => $this->facility, 'label' => 'x'], $this->auth($this->waiterToken))->assertStatus(403);
        $this->assertCount(1, $this->getJson("/api/v1/payment-terminals?facilityId={$this->facility}", $this->auth($this->waiterToken, null))->assertOk()->json('items'));
        $order = $this->billedOrder('3000.0000');
        $c = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '3000.0000', 'terminalId' => $tid, 'approvalCode' => 'T1'])->assertCreated();
        $this->assertSame($tid, $c->json('payment.collection.terminalId'));
        // assigned to another staff member / retired => unavailable
        $this->patchJson("/api/v1/payment-terminals/{$tid}", ['assignedStaffId' => $this->waiter2->id], $this->auth($it))->assertOk()->assertJsonPath('assignedStaffId', $this->waiter2->id);
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1.0000', 'terminalId' => $tid, 'approvalCode' => 'T2'])->assertStatus(409); // over-collection first
        $order2 = $this->billedOrder('1000.0000');
        $this->collect($order2, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'terminalId' => $tid, 'approvalCode' => 'T3'])->assertStatus(422)->assertJsonPath('code', 'terminal_unavailable');
        $this->patchJson("/api/v1/payment-terminals/{$tid}", ['status' => 'RETIRED', 'assignedStaffId' => null], $this->auth($it))->assertOk();
        $this->collect($order2, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'terminalId' => $tid, 'approvalCode' => 'T3'])->assertStatus(422)->assertJsonPath('code', 'terminal_unavailable');
        $this->assertSame(3, DB::table('audit_log')->whereIn('action', ['payment_terminal.create', 'payment_terminal.update'])->count());
    }

    public function test_an_integrated_terminal_adapter_that_reports_confirmed_captures_directly(): void
    {
        $adapter = new class implements PaymentTerminalAdapter
        {
            public function code(): string
            {
                return 'MANUAL_BANK';
            }

            public function initiateCharge(object $terminal, string $reference, string $amount, array $ctx): TerminalCharge
            {
                return new TerminalCharge('CONFIRMED', $reference, 'prov-1', $amount);
            }

            public function queryStatus(object $terminal, string $reference): TerminalCharge
            {
                return new TerminalCharge('CONFIRMED', $reference);
            }

            public function parseCallback(string $rawBody, ?string $signature): ?TerminalCharge
            {
                return null;
            }

            public function void(object $terminal, string $reference): bool
            {
                return true;
            }

            public function refund(object $terminal, string $reference, string $amount): bool
            {
                return true;
            }
        };
        app(TerminalAdapterRegistry::class)->extend($adapter);
        $it = $this->roleToken('itadmin', ['device.manage']);
        $tid = $this->postJson('/api/v1/payment-terminals', ['facilityId' => $this->facility, 'label' => 'Smart POS'], $this->auth($it))->assertCreated()->json('id');
        $order = $this->billedOrder('3000.0000');
        $c = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '3000.0000', 'terminalId' => $tid])->assertCreated();
        $this->assertSame('CAPTURED', $c->json('payment.status'));
        $this->assertSame('PROVIDER', $c->json('payment.collection.confirmationMode'));
        $this->assertSame('SETTLED', $this->orderStatus($order));
    }

    public function test_paystack_terminal_stub_is_disabled_by_default(): void
    {
        $it = $this->roleToken('itadmin', ['device.manage']);
        $tid = $this->postJson('/api/v1/payment-terminals', ['facilityId' => $this->facility, 'label' => 'PS', 'provider' => 'PAYSTACK_TERMINAL'], $this->auth($it))->assertCreated()->json('id');
        $order = $this->billedOrder('3000.0000');
        $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '3000.0000', 'terminalId' => $tid])->assertStatus(501)->assertJsonPath('code', 'terminal_provider_unavailable');
        $this->postJson('/api/v1/payments/terminal-callbacks/manual_bank', ['x' => 1])->assertStatus(400);
        $this->assertSame(0, DB::table('payment')->count());
    }

    // ---- ledger discipline ----------------------------------------------------------------------------------------------

    public function test_database_triggers_guard_the_new_states_and_tables(): void
    {
        $order = $this->billedOrder('3000.0000');
        $id = $this->collect($order, ['tenderType' => 'CASH', 'amount' => '3000.0000'])->assertCreated()->json('payment.id');
        $bin = Ids::toBinary($id);
        $fail = function (callable $fn, string $why) {
            try {
                $fn();
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/R007_LEDGER_IMMUTABLE|Duplicate entry/', $e->getMessage(), $why);

                return;
            }
            $this->fail("expected the ledger trigger to refuse: {$why}");
        };
        $fail(fn () => DB::table('payment')->where('id', $bin)->update(['amount' => '1.0000']), 'amount edit');
        $fail(fn () => DB::table('payment')->where('id', $bin)->update(['status' => 'REFUNDED']), 'PENDING -> REFUNDED');
        $fail(fn () => DB::table('payment')->where('id', $bin)->update(['cash_session_id' => DB::table('cash_session')->value('id')]), 'session set without capture');
        $fail(fn () => DB::table('payment_collection')->where('payment_id', $bin)->update(['note' => 'edit']), 'collection facts');
        $fail(fn () => DB::table('cash_in_hand_entry')->delete(), 'cash-in-hand delete');
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'because'], $this->auth($this->cashierToken))->assertOk();
        $fail(fn () => DB::table('payment')->where('id', $bin)->update(['status' => 'CAPTURED', 'captured_at' => now()]), 'REJECTED -> CAPTURED');
        $fail(fn () => DB::table('payment_collection_decision')->delete(), 'decision delete');
        $fail(fn () => DB::table('payment_collection_decision')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $bin, 'decision' => 'CONFIRMED', 'mode' => 'MANUAL']), 'second decision');
    }
}
