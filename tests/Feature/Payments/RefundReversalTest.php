<?php

namespace Tests\Feature\Payments;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsWorld;
use Tests\Support\TestData;
use Tests\TestCase;

class RefundReversalTest extends TestCase
{
    use PaymentsWorld;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->session = $this->openSession();
    }

    /** @return array{order: string, payment: string} */
    private function paidOrder(string $amount = '5000.0000', string $tender = 'CASH'): array
    {
        $order = $this->makeOrder($amount);
        $t = ['tenderType' => $tender, 'amount' => $amount] + ($tender === 'CASH' ? [] : ['reference' => 'REF-'.Ids::uuid7()]);
        $p = $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => $amount]], [$t], $this->session), $this->auth($this->cashierToken))->assertCreated();

        return ['order' => $order, 'payment' => $p->json('payments.0.id')];
    }

    public function test_supervisor_refund_is_applied_immediately_and_partial_then_full_refunds_walk_the_state_machine(): void
    {
        $x = $this->paidOrder('5000.0000', 'TRANSFER');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '2000.0000', 'reason' => 'Wrong item'], $this->auth($this->supervisorToken))
            ->assertCreated()->assertJsonPath('status', 'COMPLETED')->assertJsonPath('amount', '2000.0000');
        $this->getJson("/api/v1/payments/{$x['payment']}", $this->auth($this->supervisorToken, null))->assertJsonPath('status', 'PARTIALLY_REFUNDED')->assertJsonPath('refundedAmount', '2000.0000');

        // cannot exceed what is still refundable (captured - already refunded)
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '3000.0001', 'reason' => 'Too much'], $this->auth($this->supervisorToken))
            ->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '3000.0000', 'reason' => 'Rest'], $this->auth($this->supervisorToken))->assertCreated();
        $this->getJson("/api/v1/payments/{$x['payment']}", $this->auth($this->supervisorToken, null))->assertJsonPath('status', 'REFUNDED');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '0.0100', 'reason' => 'More'], $this->auth($this->supervisorToken))
            ->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');

        $this->assertSame(2, DB::table('refund')->count());
        $this->assertSame(2, DB::table('audit_log')->where('action', 'payment.refund')->count());
        $this->assertSame(2, DB::table('outbox_event')->where('event_type', 'PaymentReversed')->count());
        // the order stays settled: a refund is money out after a completed sale
        $this->assertSame('SETTLED', $this->orderStatus($x['order']));
    }

    public function test_cashier_refund_needs_supervisor_approval_and_nothing_moves_until_it_is_decided(): void
    {
        $x = $this->paidOrder('4000.0000', 'TRANSFER');
        $r = $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1000.0000', 'reason' => 'Customer complaint'], $this->auth($this->cashierToken))
            ->assertStatus(202)->assertJsonPath('status', 'PENDING_APPROVAL')->assertJsonPath('approval.action', 'payment.refund')
            ->assertJsonPath('approval.requiredPermission', 'refund.approve')->assertJsonPath('approval.status', 'PENDING');
        $this->assertSame(0, DB::table('refund')->count());
        $this->assertSame('CAPTURED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));

        // the supervisor decides through Orders' approval endpoint; approving applies the refund in the same transaction
        $approvalId = $r->json('approvalId');
        $this->getJson('/api/v1/approvals?scope=approvable', $this->auth($this->supervisorToken, null))->assertOk()->assertJsonPath('items.0.id', $approvalId);
        $this->postJson("/api/v1/approvals/{$approvalId}/decision", ['decision' => 'APPROVE', 'note' => 'ok'], $this->auth($this->cashierToken))->assertForbidden(); // requester cannot decide
        $this->postJson("/api/v1/approvals/{$approvalId}/decision", ['decision' => 'APPROVE', 'note' => 'ok'], $this->auth($this->supervisorToken))->assertOk()->assertJsonPath('status', 'APPROVED');
        $row = DB::table('refund')->first();
        $this->assertSame($r->json('id'), Ids::fromBinary($row->id), 'the refund is created with the id promised in the 202');
        $this->assertSame($approvalId, Ids::fromBinary($row->approval_id));
        $this->assertSame($this->cashier->id, Ids::fromBinary($row->requested_by_staff_id));
        $this->assertSame($this->supervisor->id, Ids::fromBinary($row->executed_by_staff_id));
        $this->assertSame('PARTIALLY_REFUNDED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));
    }

    public function test_a_rejected_refund_approval_changes_nothing(): void
    {
        $x = $this->paidOrder('4000.0000', 'TRANSFER');
        $r = $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1000.0000', 'reason' => 'Customer complaint'], $this->auth($this->cashierToken))->assertStatus(202);
        $this->postJson('/api/v1/approvals/'.$r->json('approvalId').'/decision', ['decision' => 'REJECT', 'note' => 'no'], $this->auth($this->supervisorToken))->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertSame(0, DB::table('refund')->count());
        $this->assertSame('CAPTURED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));
    }

    public function test_step_up_token_lets_a_cashier_apply_a_refund_inline(): void
    {
        $x = $this->paidOrder('4000.0000', 'TRANSFER');
        $token = $this->postJson('/api/v1/auth/staff/step-up', [
            'credentialType' => 'PIN', 'identifier' => 'super1', 'secret' => TestData::PIN, 'permission' => 'refund.approve', 'entityType' => 'Payment', 'entityId' => $x['payment'],
        ], $this->auth($this->cashierToken, null))->assertOk()->json('stepUpToken');
        $h = $this->auth($this->cashierToken) + ['X-Step-Up-Token' => $token];
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '4000.0000', 'reason' => 'Full refund'], $h)
            ->assertCreated()->assertJsonPath('status', 'COMPLETED');
        $row = DB::table('refund')->first();
        $this->assertSame($this->supervisor->id, Ids::fromBinary($row->executed_by_staff_id), 'the step-up approver is the executor of record');
        $this->assertSame($this->cashier->id, Ids::fromBinary($row->requested_by_staff_id));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'staff.step_up')->count());
        $this->assertSame('REFUNDED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));
    }

    public function test_a_facility_rule_can_waive_approval_below_a_threshold(): void
    {
        $x = $this->paidOrder('4000.0000', 'TRANSFER');
        $this->setRule('approval_threshold_amount', '500');
        // the cashier's grant is flagged requires_approval, so the flag still wins over the facility rule
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '100.0000', 'reason' => 'Small'], $this->auth($this->cashierToken))->assertStatus(202);
        // a role without the flag: below the threshold it applies directly, above it goes to approval
        $role = TestData::customRole('REF_LIGHT', 'Refund light', ['refund.execute']);
        $staff = TestData::staff($this->t, 'light1');
        TestData::assignRole($staff, $role, 'SITE');
        $tok = $this->loginToken('light1');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '100.0000', 'reason' => 'Small'], $this->auth($tok))->assertCreated();
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '600.0000', 'reason' => 'Bigger'], $this->auth($tok))->assertStatus(202);
    }

    public function test_refund_permission_is_required(): void
    {
        $x = $this->paidOrder('1000.0000', 'TRANSFER');
        $waiter = TestData::staff($this->t, 'waiter3');
        TestData::assign($waiter, 'WAIT_STAFF', 'SITE');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1.0000', 'reason' => 'abc'], $this->auth($this->loginToken('waiter3')))->assertForbidden();
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1.0000', 'reason' => 'ab'], $this->auth($this->supervisorToken))->assertStatus(422); // reason min 3
        $this->postJson('/api/v1/payments/'.Ids::uuid7().'/refund', ['amount' => '1.0000', 'reason' => 'abc'], $this->auth($this->supervisorToken))->assertNotFound();
    }

    public function test_cash_refund_comes_out_of_the_refunders_drawer_and_needs_an_open_session(): void
    {
        $x = $this->paidOrder('5000.0000', 'CASH');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1500.0000', 'reason' => 'Cash back'], $this->auth($this->supervisorToken))
            ->assertStatus(409)->assertJsonPath('code', 'cash_session_required');
        $supSession = $this->openSession($this->supervisor, '1000.0000');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1500.0000', 'reason' => 'Cash back'], $this->auth($this->supervisorToken))->assertCreated();
        $this->assertSame($supSession, Ids::fromBinary(DB::table('refund')->value('cash_session_id')));
        $this->getJson("/api/v1/cash-sessions/{$supSession}", $this->auth($this->supervisorToken, null))->assertJsonPath('expectedCash', '-500.0000'); // 1000 float - 1500 refund
        $this->getJson("/api/v1/cash-sessions/{$this->session}", $this->auth($this->cashierToken, null))->assertJsonPath('expectedCash', '10000.0000');    // 5000 float + 5000 sale (refund is on the other drawer)
    }

    public function test_reversal_undoes_a_same_session_payment_reopens_the_order_and_frees_the_cash(): void
    {
        $x = $this->paidOrder('5000.0000', 'CASH');
        $this->assertSame('SETTLED', $this->orderStatus($x['order']));
        $this->postJson("/api/v1/payments/{$x['payment']}/reversal", ['reason' => 'Wrong tender keyed'], $this->auth($this->supervisorToken))
            ->assertCreated()->assertJsonPath('status', 'COMPLETED');
        $this->assertSame('REVERSED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));
        $this->assertSame('SERVED', $this->orderStatus($x['order']), 'the order is payable again');
        $this->assertSame('0.0000', $this->paidOf($x['order']));
        $this->getJson("/api/v1/cash-sessions/{$this->session}", $this->auth($this->cashierToken, null))->assertJsonPath('expectedCash', '5000.0000');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.reverse')->count());

        // ... and the customer can now pay it properly (the CASH tender is not double counted)
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $x['order'], 'amount' => '5000.0000']], [['tenderType' => 'POS_TERMINAL', 'amount' => '5000.0000', 'reference' => 'RRN-R1']]), $this->auth($this->cashierToken))->assertCreated();
        // a reversed payment cannot be reversed or refunded again
        $this->postJson("/api/v1/payments/{$x['payment']}/reversal", ['reason' => 'Again'], $this->auth($this->supervisorToken))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
        $this->postJson("/api/v1/payments/{$x['payment']}/refund", ['amount' => '1.0000', 'reason' => 'Again'], $this->auth($this->supervisorToken))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
    }

    public function test_reversal_is_refused_once_the_cash_session_is_closed_or_the_payment_was_partly_refunded(): void
    {
        $x = $this->paidOrder('5000.0000', 'TRANSFER');
        $this->postJson("/api/v1/cash-sessions/{$this->session}/close", ['countedCash' => '5000.0000'], $this->auth($this->cashierToken))->assertOk();
        $this->postJson("/api/v1/payments/{$x['payment']}/reversal", ['reason' => 'Late fix'], $this->auth($this->supervisorToken))
            ->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');

        $y = $this->paidOrder2();
        $this->postJson("/api/v1/payments/{$y}/refund", ['amount' => '10.0000', 'reason' => 'Bit'], $this->auth($this->supervisorToken))->assertCreated();
        $this->postJson("/api/v1/payments/{$y}/reversal", ['reason' => 'Nope'], $this->auth($this->supervisorToken))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
    }

    private function paidOrder2(): string
    {
        $this->session = $this->openSession();
        $order = $this->makeOrder('500.0000');

        return $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '500.0000']], [['tenderType' => 'TRANSFER', 'amount' => '500.0000', 'reference' => 'Q-'.Ids::uuid7()]], $this->session), $this->auth($this->cashierToken))->json('payments.0.id');
    }

    public function test_cashier_reversal_goes_to_approval(): void
    {
        $x = $this->paidOrder('5000.0000', 'CASH');
        $r = $this->postJson("/api/v1/payments/{$x['payment']}/reversal", ['reason' => 'Wrong tender'], $this->auth($this->cashierToken))
            ->assertStatus(202)->assertJsonPath('approval.action', 'payment.reversal')->assertJsonPath('approval.requiredPermission', 'payment.reversal.approve');
        $this->assertSame('CAPTURED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));
        $this->postJson('/api/v1/approvals/'.$r->json('approvalId').'/decision', ['decision' => 'APPROVE'], $this->auth($this->supervisorToken))->assertOk();
        $this->assertSame($r->json('id'), Ids::fromBinary(DB::table('reversal')->value('id')));
        $this->assertSame('REVERSED', DB::table('payment')->where('id', Ids::toBinary($x['payment']))->value('status'));
        $this->assertSame('SERVED', $this->orderStatus($x['order']));
    }
}
