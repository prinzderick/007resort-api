<?php

namespace Tests\Feature\Orders;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class OrderStateMachineTest extends OrdersTestCase
{
    public function test_lines_can_be_added_and_removed_only_while_draft(): void
    {
        $d = $this->draft(['jollof' => 1]);
        $id = $d->json('id');
        $add = $this->api('waiter', 'POST', "/orders/{$id}/lines", ['productId' => $this->f->products['suya'], 'quantity' => 2], ['If-Match' => $this->etag($d)])->assertStatus(201);
        $this->assertSame('10500.0000', $add->json('total'));
        $lineId = $add->json('lines.1.id');
        $rm = $this->api('waiter', 'DELETE', "/orders/{$id}/lines/{$lineId}", [], ['If-Match' => $this->etag($add)])->assertOk();
        $this->assertSame('4500.0000', $rm->json('total'));
        $this->assertCount(1, $rm->json('lines'));

        $sent = $this->send($id, $this->etag($rm))->assertOk();
        $this->assertSame('SENT', $sent->json('status'));
        $this->assertSame('ROUTED', $sent->json('lines.0.status'));

        $this->api('waiter', 'POST', "/orders/{$id}/lines", ['productId' => $this->f->products['suya'], 'quantity' => 1], ['If-Match' => $this->etag($sent)])
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');
        $this->api('waiter', 'DELETE', "/orders/{$id}/lines/".$sent->json('lines.0.id'), [], ['If-Match' => $this->etag($sent)])
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');
        $this->send($id, $this->etag($sent))->assertStatus(409)->assertJsonPath('code', 'order_state_invalid'); // double send
    }

    public function test_empty_order_cannot_be_sent_and_serve_needs_readiness(): void
    {
        $d = $this->api('waiter', 'POST', '/orders', ['facilityId' => $this->f->restaurant->id, 'tableId' => $this->f->tables['T2']])->assertStatus(201);
        $this->send($d->json('id'), $this->etag($d))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/serve', [], ['If-Match' => $this->etag($d)])->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');

        $s = $this->sent(['jollof' => 1], 'T3');
        $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/serve', [], ['If-Match' => $this->etag($s)])
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid'); // still cooking
    }

    public function test_if_match_is_mandatory_and_stale_versions_are_rejected(): void
    {
        $d = $this->draft(['jollof' => 1]);
        $id = $d->json('id');
        $this->api('waiter', 'POST', "/orders/{$id}/lines", ['productId' => $this->f->products['suya'], 'quantity' => 1])
            ->assertStatus(428)->assertJsonPath('code', 'concurrency_conflict');
        $this->api('waiter', 'POST', "/orders/{$id}/lines", ['productId' => $this->f->products['suya'], 'quantity' => 1], ['If-Match' => '"999"'])
            ->assertStatus(412)->assertJsonPath('code', 'concurrency_conflict');
        $ok = $this->api('waiter', 'POST', "/orders/{$id}/lines", ['productId' => $this->f->products['suya'], 'quantity' => 1], ['If-Match' => $this->etag($d)])->assertStatus(201);
        $this->assertNotSame($this->etag($d), $this->etag($ok));
        // the old ETag is now stale
        $this->send($id, $this->etag($d))->assertStatus(412);
    }

    public function test_unrouted_retail_order_is_served_straight_from_sent(): void
    {
        $s = $this->sent(['water' => 2], 'T2');
        $this->assertSame(0, DB::table('prep_ticket')->count());
        $this->assertSame('LOCKED', $s->json('lines.0.status'));
        $served = $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/serve', [], ['If-Match' => $this->etag($s)])->assertOk();
        $this->assertSame('SERVED', $served->json('status'));
        $this->assertSame('DISPENSED', $served->json('lines.0.status'));
    }

    public function test_prep_ticket_illegal_transitions_and_ladder(): void
    {
        $s = $this->sent(['jollof' => 1], 'T1');
        $t = DB::table('prep_ticket')->first();
        $tid = Ids::fromBinary($t->id);
        $get = fn () => $this->api('chef', 'GET', "/prep-tickets/{$tid}");
        $go = fn (string $to) => $this->api('chef', 'POST', "/prep-tickets/{$tid}/transition", ['to' => $to], ['If-Match' => $this->etag($get())]);

        $go('READY')->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');      // NEW -> READY
        $go('DISPENSED')->assertStatus(409)->assertJsonPath('code', 'order_state_invalid');  // NEW -> DISPENSED
        $go('ACCEPTED')->assertOk();
        $go('ACCEPTED')->assertStatus(409);                                                   // no self-transition
        $r = $go('READY')->assertOk();                                                        // ACCEPTED -> READY may skip
        $this->assertNotNull($r->json('readyAt'));
        $this->assertNotNull($r->json('acceptedAt'));
        $go('IN_PROGRESS')->assertStatus(409);                                                // no going back
        $this->assertSame('READY', $this->api('waiter', 'GET', '/orders/'.$s->json('id'))->json('status'));
        // staff + timestamps recorded
        $row = DB::table('prep_ticket')->where('id', $t->id)->first();
        $this->assertNotNull($row->ready_by);
        $this->assertNotNull($row->accepted_by);
        $go('DISPENSED')->assertOk();
        $go('DISPENSED')->assertStatus(409);
        // stale ETag on a ticket
        $this->api('chef', 'POST', "/prep-tickets/{$tid}/transition", ['to' => 'DISPENSED'], ['If-Match' => '"1"'])->assertStatus(412);
    }

    public function test_order_numbers_are_sequential_per_facility_and_unique(): void
    {
        $a = $this->draft(['jollof' => 1], 'waiter', 'T1')->json('number');
        $b = $this->draft(['jollof' => 1], 'waiter', 'T2')->json('number');
        $this->assertSame('RST1-000001', $a);
        $this->assertSame('RST1-000002', $b);
    }

    public function test_kds_board_lists_active_tickets_with_rowversion_and_etag(): void
    {
        $this->sent(['jollof' => 1, 'chapman' => 2], 'T1');
        $board = $this->api('chef', 'GET', "/kds/stations/{$this->f->kitchenStation}/tickets")->assertOk();
        $this->assertCount(1, $board->json('items'));
        $tk = $board->json('items.0');
        $this->assertSame('NEW', $tk['status']);
        $this->assertSame('K-001', $tk['number']);
        $this->assertSame('T1', $tk['tableLabel']);
        $this->assertSame('Jollof Rice & Chicken', $tk['items'][0]['name']);
        $this->assertArrayHasKey('rowVersion', $tk);
        $this->api('chef', 'GET', "/prep-tickets/{$tk['id']}")->assertHeader('ETag');
        $stations = $this->api('chef', 'GET', "/kds/stations?facilityId={$this->f->restaurant->id}")->assertOk();
        $this->assertCount(2, $stations->json('items'));
        // waiter has no prep_ticket.view
        $this->api('waiter', 'GET', "/kds/stations/{$this->f->kitchenStation}/tickets")->assertStatus(403);
    }
}
