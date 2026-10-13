<?php

namespace Tests\Feature\Orders;

use App\Domain\Orders\Services\OperatingRules;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Waiter-only service flow (facility rule service_flow=WAITER_ONLY): send, serve, pay many, supervisor board, hand-over, print jobs. */
class WaiterOnlyFlowTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->f->capability($this->f->restaurant, 'TABLE_SERVICE', ['service_flow' => 'WAITER_ONLY']);
        $this->f->capability($this->f->restaurant, 'OPEN_TAB', ['payment_timing' => 'PAY_ON_EXIT']);
    }

    /** SENT + SERVED order for $user on $table; returns [orderId, tabId]. */
    private function served(array $lines = ['jollof' => 1], string $user = 'waiter', string $table = 'T1'): array
    {
        $d = $this->draft($lines, $user, $table);
        $sent = $this->send($d->json('id'), $this->etag($d), $user)->assertOk();
        $served = $this->api($user, 'POST', '/orders/'.$d->json('id').'/serve', [], ['If-Match' => $this->etag($sent)])->assertOk();
        $this->assertSame('SERVED', $served->json('status'));

        return [$d->json('id'), $served->json('tabId')];
    }

    private function fid(): string
    {
        return $this->f->restaurant->id;
    }

    private function id(string $user): string
    {
        return Ids::fromBinary(DB::table('staff')->where('staff_number', $user)->value('id') ?? DB::table('user_account')->where('username', $user)->value('staff_id'));
    }

    public function test_send_creates_no_kitchen_tickets_and_serve_is_audited(): void
    {
        $d = $this->draft(['jollof' => 1, 'chapman' => 1]);
        $sent = $this->send($d->json('id'), $this->etag($d))->assertOk();
        $this->assertSame('SENT', $sent->json('status'));
        $this->assertSame(0, DB::table('prep_ticket')->count(), 'waiter-only: nothing goes to a KDS');
        $served = $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/serve', [], ['If-Match' => $this->etag($sent)])->assertOk();
        $this->assertSame('SERVED', $served->json('status'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'order.serve')->count());
    }

    public function test_supervisor_can_serve_a_waiters_order(): void
    {
        $d = $this->draft();
        $sent = $this->send($d->json('id'), $this->etag($d))->assertOk();
        $this->api('supervisor', 'POST', '/orders/'.$d->json('id').'/serve', [], ['If-Match' => $this->etag($sent)])->assertOk()->assertJsonPath('status', 'SERVED');
    }

    public function test_waiter_pays_several_orders_on_different_tabs_in_one_call(): void
    {
        [$o1, $tab1] = $this->served(['jollof' => 1], 'waiter', 'T1');           // 4500
        [$o2, $tab2] = $this->served(['suya' => 2, 'water' => 1], 'waiter', 'T2'); // 6500
        $this->assertNotSame($tab1, $tab2);
        $r = $this->api('waiter', 'POST', '/waiter-flow/settle', [
            'facilityId' => $this->fid(), 'orderIds' => [$o1, $o2],
            'tenders' => [['tenderType' => 'CASH', 'amount' => '8000', 'tendered' => '10000'], ['tenderType' => 'TRANSFER', 'amount' => '3000', 'reference' => 'TRF-1234']],
        ])->assertStatus(201);
        $this->assertNotEmpty($r->json('receiptId'));
        foreach ($r->json('orders') as $o) {
            $this->assertSame('SETTLED', $o['status']);
        }
        $this->assertEqualsCanonicalizing([Ids::normalize($tab1), Ids::normalize($tab2)], array_map(Ids::normalize(...), $r->json('closedTabIds')));
        $this->assertSame(0, DB::table('tab')->whereIn('id', [Ids::toBinary($tab1), Ids::toBinary($tab2)])->where('status', 'OPEN')->count());
        // cash is the waiter's responsibility until handed over
        $this->assertSame(1, DB::table('cash_in_hand_entry')->where('kind', 'COLLECTED')->count());
        $this->assertSame('8000.0000', number_format((float) DB::table('cash_in_hand_entry')->sum('amount'), 4, '.', ''));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payment.waiter_settle')->count());
        // paying again is refused
        $this->api('waiter', 'POST', '/waiter-flow/settle', ['facilityId' => $this->fid(), 'orderIds' => [$o1], 'tenders' => [['tenderType' => 'CASH', 'amount' => '4500']]])->assertStatus(409);
    }

    public function test_a_waiter_cannot_pay_another_waiters_order_but_a_supervisor_can_on_their_behalf(): void
    {
        [$o, $tab] = $this->served(['jollof' => 1], 'waiter2', 'T3');
        $body = ['facilityId' => $this->fid(), 'orderIds' => [$o], 'tenders' => [['tenderType' => 'CASH', 'amount' => '4500']]];
        $this->api('waiter', 'POST', '/waiter-flow/settle', $body)->assertStatus(403)->assertJsonPath('code', 'not_your_order');
        $r = $this->api('supervisor', 'POST', '/waiter-flow/settle', $body)->assertStatus(201);
        $this->assertSame('SETTLED', $r->json('orders.0.status'));
        $row = DB::table('payment')->first();
        $this->assertSame($this->id('supervisor'), Ids::fromBinary($row->taken_by_staff_id));
        $this->assertSame($this->id('waiter2'), Ids::fromBinary($row->owner_staff_id), 'the payment remembers whose tab it was');
        $audit = DB::table('audit_log')->where('action', 'payment.waiter_settle')->first();
        $this->assertStringContainsString($this->id('waiter2'), (string) $audit->new_value);
    }

    public function test_card_and_transfer_need_a_reference_and_unserved_orders_are_refused(): void
    {
        [$o] = $this->served();
        $this->api('waiter', 'POST', '/waiter-flow/settle', ['facilityId' => $this->fid(), 'orderIds' => [$o], 'tenders' => [['tenderType' => 'TRANSFER', 'amount' => '4500']]])
            ->assertStatus(422);
        $d = $this->draft(['suya' => 1], 'waiter', 'T2');
        $sent = $this->send($d->json('id'), $this->etag($d))->assertOk();
        $this->api('waiter', 'POST', '/waiter-flow/settle', ['facilityId' => $this->fid(), 'orderIds' => [$d->json('id')], 'tenders' => [['tenderType' => 'CASH', 'amount' => '3000']]])
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');
        $this->assertSame('SENT', $sent->json('status'));
    }

    public function test_the_flow_must_be_switched_on_for_the_facility(): void
    {
        [$o] = $this->served();
        DB::table('operating_rule')->where('rule_key', 'service_flow')->update(['rule_value' => 'STANDARD']);
        app(OperatingRules::class)->flush();
        $this->api('waiter', 'POST', '/waiter-flow/settle', ['facilityId' => $this->fid(), 'orderIds' => [$o], 'tenders' => [['tenderType' => 'CASH', 'amount' => '4500']]])
            ->assertStatus(409)->assertJsonPath('code', 'flow_not_enabled');
    }

    public function test_supervisor_sees_every_open_tab_with_its_waiter_and_a_waiter_sees_only_their_own(): void
    {
        $this->served(['jollof' => 1], 'waiter', 'T1');
        $this->served(['suya' => 1], 'waiter2', 'T2');
        $mine = $this->api('waiter', 'GET', '/waiter-flow/open-items?facilityId='.$this->fid())->assertOk();
        $this->assertSame('OWN', $mine->json('scope'));
        $this->assertCount(1, $mine->json('groups'));
        $all = $this->api('supervisor', 'GET', '/waiter-flow/open-items?facilityId='.$this->fid())->assertOk();
        $this->assertSame('ALL', $all->json('scope'));
        $this->assertCount(2, $all->json('groups'));
        $this->assertEqualsCanonicalizing(['waiter', 'waiter2'], array_map(fn ($g) => strtolower(explode(' ', $g['ownerName'])[0]), $all->json('groups')));
        $this->assertCount(2, $all->json('waiters'));
        $this->assertSame('7500.0000', $all->json('totals.balance'));
    }

    public function test_handover_must_be_accepted_by_the_receiving_waiter_and_moves_ownership(): void
    {
        [$o1] = $this->served(['jollof' => 1], 'waiter', 'T1');
        [$o2] = $this->served(['suya' => 1], 'waiter', 'T2');
        $req = $this->api('waiter', 'POST', '/waiter-flow/handovers', ['facilityId' => $this->fid(), 'toStaffId' => $this->id('waiter2'), 'note' => 'end of shift'])->assertStatus(201);
        $this->assertSame('PENDING', $req->json('status'));
        $this->assertSame(2, $req->json('orderCount'));
        $hid = $req->json('id');
        // nothing moved yet
        $this->assertSame(0, DB::table('order')->where('responsible_staff_id', Ids::toBinary($this->id('waiter2')))->count());
        // only the receiver may accept
        $this->api('waiter', 'POST', "/waiter-flow/handovers/{$hid}/accept")->assertStatus(403);
        $this->api('supervisor', 'POST', "/waiter-flow/handovers/{$hid}/accept")->assertStatus(403);
        $this->assertCount(1, $this->api('waiter2', 'GET', '/waiter-flow/handovers?facilityId='.$this->fid().'&direction=incoming')->json('items'));
        $acc = $this->api('waiter2', 'POST', "/waiter-flow/handovers/{$hid}/accept")->assertOk();
        $this->assertSame('ACCEPTED', $acc->json('status'));
        $this->assertCount(2, $acc->json('moved'));
        $this->assertSame(2, DB::table('order')->where('responsible_staff_id', Ids::toBinary($this->id('waiter2')))->count());
        // the new owner can now pay, the previous one cannot
        $body = ['facilityId' => $this->fid(), 'orderIds' => [$o1, $o2], 'tenders' => [['tenderType' => 'CASH', 'amount' => '7500']]];
        $this->api('waiter', 'POST', '/waiter-flow/settle', $body)->assertStatus(403);
        $this->api('waiter2', 'POST', '/waiter-flow/settle', $body)->assertStatus(201);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'tab.handover.accept')->count());
        $this->assertSame(2, DB::table('audit_log')->where('action', 'order.reassign')->count());
        // created_by is never rewritten
        $this->assertSame($this->id('waiter'), Ids::fromBinary(DB::table('order')->where('id', Ids::toBinary($o1))->value('created_by')));
        // cannot decide twice
        $this->api('waiter2', 'POST', "/waiter-flow/handovers/{$hid}/accept")->assertStatus(409);
    }

    public function test_waiters_list_offers_colleagues_who_can_take_orders(): void
    {
        $names = array_column($this->api('waiter', 'GET', '/waiter-flow/waiters?facilityId='.$this->fid())->assertOk()->json('items'), 'staffId');
        $this->assertContains($this->id('waiter2'), $names);
        $this->assertNotContains($this->id('waiter'), $names, 'not yourself');
        $this->assertNotContains($this->id('chef'), $names, 'kitchen staff cannot take tabs');
    }

    public function test_rejected_cancelled_and_expired_handovers_move_nothing(): void
    {
        $this->served(['jollof' => 1], 'waiter', 'T1');
        $b = ['facilityId' => $this->fid(), 'toStaffId' => $this->id('waiter2')];
        $h1 = $this->api('waiter', 'POST', '/waiter-flow/handovers', $b)->assertStatus(201)->json('id');
        // a second request while one is pending is refused
        $this->api('waiter', 'POST', '/waiter-flow/handovers', $b)->assertStatus(409)->assertJsonPath('code', 'handover_pending');
        $this->api('waiter2', 'POST', "/waiter-flow/handovers/{$h1}/reject", ['reason' => 'busy'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $h2 = $this->api('waiter', 'POST', '/waiter-flow/handovers', $b)->assertStatus(201)->json('id');
        $this->api('waiter', 'POST', "/waiter-flow/handovers/{$h2}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $h3 = $this->api('waiter', 'POST', '/waiter-flow/handovers', $b)->assertStatus(201)->json('id');
        DB::table('tab_handover')->where('id', Ids::toBinary($h3))->update(['expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->api('waiter2', 'POST', "/waiter-flow/handovers/{$h3}/accept")->assertStatus(409)->assertJsonPath('code', 'handover_expired');
        $this->assertSame(0, DB::table('order')->whereNotNull('responsible_staff_id')->count());
    }

    public function test_supervisor_can_force_reassign_with_a_reason(): void
    {
        [$o] = $this->served(['jollof' => 1], 'waiter', 'T1');
        $this->api('waiter', 'POST', '/waiter-flow/reassign', ['facilityId' => $this->fid(), 'toStaffId' => $this->id('waiter2'), 'orderIds' => [$o], 'reason' => 'left early'])->assertStatus(403);
        $r = $this->api('supervisor', 'POST', '/waiter-flow/reassign', ['facilityId' => $this->fid(), 'toStaffId' => $this->id('waiter2'), 'fromStaffId' => $this->id('waiter'), 'reason' => 'left early'])->assertOk();
        $this->assertSame('FORCED', $r->json('status'));
        $this->assertSame($this->id('waiter2'), Ids::fromBinary(DB::table('order')->where('id', Ids::toBinary($o))->value('responsible_staff_id')));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'tab.handover.forced')->count());
    }

    public function test_receipt_can_be_queued_for_printing_and_marked_printed_from_a_terminal(): void
    {
        [$o] = $this->served();
        $paid = $this->api('waiter', 'POST', '/waiter-flow/settle', ['facilityId' => $this->fid(), 'orderIds' => [$o], 'tenders' => [['tenderType' => 'CASH', 'amount' => '4500']]])->assertStatus(201);
        $rid = $paid->json('receiptId');
        $job = $this->api('waiter', 'POST', "/receipts/{$rid}/print-jobs", ['copies' => 2])->assertStatus(201);
        $this->assertSame('QUEUED', $job->json('status'));
        $again = $this->api('waiter', 'POST', "/receipts/{$rid}/print-jobs")->assertStatus(201);
        $this->assertSame($job->json('id'), $again->json('id'), 'double tap does not queue twice');
        $q = $this->api('supervisor', 'GET', '/print-jobs?facilityId='.$this->fid())->assertOk();
        $this->assertCount(1, $q->json('items'));
        $this->assertNotEmpty($q->json('items.0.receipt.printLines'));
        $this->api('supervisor', 'POST', '/print-jobs/'.$job->json('id').'/printed')->assertOk()->assertJsonPath('status', 'PRINTED');
        $this->assertCount(0, $this->api('supervisor', 'GET', '/print-jobs?facilityId='.$this->fid())->json('items'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'receipt.print.printed')->count());
    }
}
