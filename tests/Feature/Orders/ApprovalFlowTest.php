<?php

namespace Tests\Feature\Orders;

use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;

/** Sensitive actions: request -> supervisor decision -> applied atomically -> audited. */
class ApprovalFlowTest extends OrdersTestCase
{
    public function test_void_of_a_sent_order_end_to_end(): void
    {
        $s = $this->sent(['jollof' => 1, 'chapman' => 1]);
        $id = $s->json('id');

        $r = $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'Guest left'], ['If-Match' => $this->etag($s)])->assertStatus(202);
        $this->assertSame('PENDING_APPROVAL', $r->json('status'));
        $this->assertSame('PENDING', $r->json('approval.status'));
        $this->assertSame('order.void', $r->json('approval.action'));
        $this->assertSame('order.void.approve', $r->json('approval.requiredPermission'));
        $this->assertSame('PENDING_APPROVAL', $r->json('order.status'));
        $approvalId = $r->json('approval.id');
        $this->assertSame(1, DB::table('approval')->where('status', 'PENDING')->count());

        // the order is frozen while the approval is pending
        $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'again'], ['If-Match' => $this->etag($r)])->assertStatus(409)->assertJsonPath('code', 'approval_pending');
        $this->assertSame(2, DB::table('prep_ticket')->where('status', 'NEW')->count());

        // requester cannot decide their own request; waiter has no approve permission
        $this->api('waiter', 'POST', "/approvals/{$approvalId}/decision", ['decision' => 'APPROVE'])->assertStatus(403)->assertJsonPath('code', 'permission_denied');

        // supervisor sees it in the approvable queue
        $q = $this->api('supervisor', 'GET', '/approvals?scope=approvable')->assertOk();
        $this->assertSame([$approvalId], collect($q->json('items'))->pluck('id')->all());
        $this->assertSame('Void order '.$s->json('number').' (7,000.00)', $q->json('items.0.summary'));

        $d = $this->api('supervisor', 'POST', "/approvals/{$approvalId}/decision", ['decision' => 'APPROVE', 'note' => 'ok'])->assertOk();
        $this->assertSame('APPROVED', $d->json('status'));
        $this->assertSame($this->f->staff['supervisor']->id, $d->json('decidedByStaffId'));

        $order = $this->api('waiter', 'GET', "/orders/{$id}")->assertOk();
        $this->assertSame('VOIDED', $order->json('status'));
        $this->assertSame('0.0000', $order->json('balanceDue'));
        $this->assertSame(['VOIDED', 'VOIDED'], collect($order->json('lines'))->pluck('status')->all());
        $this->assertSame(2, DB::table('prep_ticket')->where('status', 'CANCELLED')->count());
        $this->assertSame(2, DB::table('line_void')->where('approval_id', Ids::toBinary($approvalId))->count());
        $this->assertSame('FREE', DB::table('dining_table')->where('id', Ids::toBinary($this->f->tables['T1']))->value('status'));

        // audit trail (hash chain intact) + outbox
        $actions = DB::table('audit_log')->whereIn('action', ['order.void.request', 'order.void.approve', 'order.void'])->orderBy('seq')->pluck('action')->all();
        $this->assertSame(['order.void.request', 'order.void', 'order.void.approve'], $actions);
        $this->assertSame(2, DB::table('audit_log')->whereIn('action', ['order.void', 'order.void.approve'])->where('approval_id', Ids::toBinary($approvalId))->count());
        $this->assertSame($this->f->staff['supervisor']->id, Ids::fromBinary(DB::table('audit_log')->where('action', 'order.void')->value('actor_staff_id')));
        $this->assertTrue(Audit::verifyChain()->valid);
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OrderVoided')->count());

        // decided approvals cannot be decided again
        $this->api('supervisor', 'POST', "/approvals/{$approvalId}/decision", ['decision' => 'APPROVE'])->assertStatus(409)->assertJsonPath('code', 'approval_already_decided');
    }

    public function test_rejected_void_restores_the_order(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $id = $s->json('id');
        $r = $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'Changed mind'], ['If-Match' => $this->etag($s)])->assertStatus(202);
        $this->api('supervisor', 'POST', '/approvals/'.$r->json('approval.id').'/decision', ['decision' => 'REJECT', 'note' => 'Guest is still here'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $o = $this->api('waiter', 'GET', "/orders/{$id}");
        $this->assertSame('SENT', $o->json('status'));
        $this->assertNull($o->json('pendingApprovalId'));
        $this->assertSame(1, DB::table('prep_ticket')->where('status', 'NEW')->count());
        $this->assertSame(0, DB::table('line_void')->count());
    }

    public function test_requester_can_cancel_a_pending_approval(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $r = $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'oops'], ['If-Match' => $this->etag($s)])->assertStatus(202);
        $aid = $r->json('approval.id');
        $this->api('supervisor', 'POST', "/approvals/{$aid}/cancel")->assertStatus(403);
        $this->api('waiter', 'POST', "/approvals/{$aid}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('SENT', $this->api('waiter', 'GET', '/orders/'.$s->json('id'))->json('status'));
        $this->api('supervisor', 'POST', "/approvals/{$aid}/decision", ['decision' => 'APPROVE'])->assertStatus(409);
    }

    public function test_supervisor_with_the_approve_permission_voids_immediately(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $r = $this->api('supervisor', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'Kitchen error'], ['If-Match' => $this->etag($s)])->assertOk();
        $this->assertSame('VOIDED', $r->json('status'));
        $this->assertSame(0, DB::table('approval')->whereNotNull('action')->count());
    }

    public function test_inline_step_up_token_lets_the_waiter_void_with_a_supervisors_authority(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $id = $s->json('id');
        $tok = $this->stepUp('waiter', 'supervisor', 'order.void.approve', $id);
        $r = $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'Wrong table'], ['If-Match' => $this->etag($s), 'X-Step-Up-Token' => $tok])->assertOk();
        $this->assertSame('VOIDED', $r->json('status'));
        $void = DB::table('line_void')->first();
        $this->assertSame($this->f->staff['supervisor']->id, Ids::fromBinary($void->approved_by));
        $this->assertSame($this->f->staff['waiter']->id, Ids::fromBinary($void->voided_by));
    }

    public function test_invalid_or_wrong_permission_step_up_token_is_refused(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $id = $s->json('id');
        $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'x y z'], ['If-Match' => $this->etag($s), 'X-Step-Up-Token' => 'r7s_bogus'])->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $tok = $this->stepUp('waiter', 'supervisor', 'order.discount.approve');
        $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'x y z'], ['If-Match' => $this->etag($s), 'X-Step-Up-Token' => $tok])->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->assertSame('SENT', $this->api('waiter', 'GET', "/orders/{$id}")->json('status'));
    }

    public function test_unsent_draft_can_be_cancelled_by_a_holder_of_the_execute_permission(): void
    {
        $d = $this->draft(['jollof' => 1]);
        $r = $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/void', ['reason' => 'Guest left'], ['If-Match' => $this->etag($d)])->assertOk();
        $this->assertSame('VOIDED', $r->json('status'));
        $this->assertSame('FREE', DB::table('dining_table')->where('id', Ids::toBinary($this->f->tables['T1']))->value('status'));
    }

    public function test_facility_rule_can_force_approval_even_for_drafts(): void
    {
        $this->f->capability($this->f->restaurant, 'POS', ['require_approval_for' => 'order.void']);
        $d = $this->draft(['jollof' => 1]);
        $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/void', ['reason' => 'Guest left'], ['If-Match' => $this->etag($d)])->assertStatus(202);
    }

    public function test_discount_needs_approval_above_the_facility_threshold(): void
    {
        $this->f->capability($this->f->restaurant, 'POS', ['approval_threshold_amount' => '500']);
        $s = $this->sent(['jollof' => 2], 'T1');
        $id = $s->json('id');
        $lineId = $s->json('lines.0.id');

        // 5 % of 9000 = 450 <= threshold: applied directly
        $ok = $this->api('waiter', 'POST', "/orders/{$id}/lines/{$lineId}/adjustments", ['kind' => 'DISCOUNT_PERCENT', 'value' => '5', 'reason' => 'Loyal guest'], ['If-Match' => $this->etag($s)])->assertOk();
        $this->assertSame('450.0000', $ok->json('discountTotal'));
        $this->assertSame('8550.0000', $ok->json('total'));
        $this->assertSame('APPLIED', $ok->json('lines.0.adjustments.0.status'));

        // 10 % (900) > threshold: pending approval, totals untouched
        $p = $this->api('waiter', 'POST', "/orders/{$id}/lines/{$lineId}/adjustments", ['kind' => 'DISCOUNT_PERCENT', 'value' => '10', 'reason' => 'Complaint'], ['If-Match' => $this->etag($ok)])->assertStatus(202);
        $this->assertSame('order.adjust', $p->json('approval.action'));
        $this->assertSame('order.discount.approve', $p->json('approval.requiredPermission'));
        $this->assertSame('8550.0000', $this->api('waiter', 'GET', "/orders/{$id}")->json('total'));

        $this->api('supervisor', 'POST', '/approvals/'.$p->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertOk();
        $after = $this->api('waiter', 'GET', "/orders/{$id}");
        $this->assertSame('1350.0000', $after->json('discountTotal'));
        $this->assertSame('7650.0000', $after->json('total'));
        $this->assertSame(['APPLIED', 'APPLIED'], collect($after->json('lines.0.adjustments'))->pluck('status')->all());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'order.line.adjust')->where('approval_id', Ids::toBinary($p->json('approval.id')))->count());
    }

    public function test_staff_without_the_execute_permission_cannot_even_request_a_comp(): void
    {
        $s = $this->sent(['jollof' => 1, 'suya' => 1], 'T1');
        $id = $s->json('id');
        $this->api('chef', 'POST', "/orders/{$id}/lines/".$s->json('lines.0.id').'/adjustments', ['kind' => 'COMP', 'value' => '1', 'reason' => 'Birthday'], ['If-Match' => $this->etag($s)])
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'order.comp.execute');
        // the waiter may REQUEST a comp (execute permission) but a supervisor must approve
        $this->api('waiter', 'POST', "/orders/{$id}/lines/".$s->json('lines.0.id').'/adjustments', ['kind' => 'COMP', 'value' => '1', 'reason' => 'Birthday'], ['If-Match' => $this->etag($s)])
            ->assertStatus(202)->assertJsonPath('approval.requiredPermission', 'order.comp.approve');
        $this->assertSame('7500.0000', $this->api('waiter', 'GET', "/orders/{$id}")->json('total'));
    }

    public function test_supervisor_applies_comp_and_price_override_directly(): void
    {
        $s = $this->sent(['jollof' => 1, 'suya' => 1], 'T1');
        $id = $s->json('id');
        $a = $this->api('supervisor', 'POST', "/orders/{$id}/lines/".$s->json('lines.0.id').'/adjustments', ['kind' => 'COMP', 'value' => '1', 'reason' => 'Birthday'], ['If-Match' => $this->etag($s)])->assertOk();
        $this->assertSame('3000.0000', $a->json('total'));
        $b = $this->api('supervisor', 'POST', "/orders/{$id}/lines/".$s->json('lines.1.id').'/adjustments', ['kind' => 'PRICE_OVERRIDE', 'value' => '2000', 'reason' => 'Manager special'], ['If-Match' => $this->etag($a)])->assertOk();
        $this->assertSame('2000.0000', $b->json('total'));
        $this->assertSame('4500.0000', $b->json('discountTotal'));
        $this->assertSame(2, DB::table('audit_log')->where('action', 'order.line.adjust')->count());
    }

    public function test_cashier_may_request_a_comp_which_a_supervisor_approves(): void
    {
        $cashier = TestData::staff($this->f->t, 'cashier');
        TestData::assign($cashier, 'CASHIER', 'FACILITY_UNIT', $this->f->restaurant->id);
        $d = $this->draft(['suya' => 1], 'waiter', 'T2');
        $r = $this->api('cashier', 'POST', '/orders/'.$d->json('id').'/lines/'.$d->json('lines.0.id').'/adjustments', ['kind' => 'COMP', 'value' => '1', 'reason' => 'Staff meal'], ['If-Match' => $this->etag($d)])->assertStatus(202);
        $this->api('supervisor', 'POST', '/approvals/'.$r->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertOk();
        $this->assertSame('0.0000', $this->api('waiter', 'GET', '/orders/'.$d->json('id'))->json('total'));
    }
}
