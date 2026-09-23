<?php

namespace Tests\Feature\Orders;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class OrderFlowTest extends OrdersTestCase
{
    public function test_draft_send_prep_serve_happy_path(): void
    {
        $r = $this->draft(['jollof' => 2, 'chapman' => 1]);
        $id = $r->json('id');
        $this->assertSame('DRAFT', $r->json('status'));
        $this->assertSame('11500.0000', $r->json('total'));
        $this->assertSame('0.0000', $r->json('taxTotal'));
        $this->assertStringStartsWith('RST1-', $r->json('number'));

        $sent = $this->send($id, $this->etag($r))->assertOk();
        $this->assertSame('SENT', $sent->json('status'));
        $this->assertSame(2, DB::table('prep_ticket')->count());
        // routing: jollof -> kitchen, chapman -> bar
        $kitchen = DB::table('prep_ticket')->where('station_id', Ids::toBinary($this->f->kitchenStation))->first();
        $bar = DB::table('prep_ticket')->where('station_id', Ids::toBinary($this->f->barStation))->first();
        $this->assertSame(1, DB::table('prep_ticket_item')->where('prep_ticket_id', $kitchen->id)->count());
        $this->assertSame(2, (int) DB::table('prep_ticket_item')->where('prep_ticket_id', $kitchen->id)->value('quantity'));
        $this->assertSame('B-001', $bar->ticket_number);

        $tid = Ids::fromBinary($kitchen->id);
        $t = $this->api('chef', 'GET', "/prep-tickets/{$tid}")->assertOk();
        $tr = $this->api('chef', 'POST', "/prep-tickets/{$tid}/transition", ['to' => 'ACCEPTED'], ['If-Match' => $this->etag($t)])->assertOk();
        $this->assertSame('ACCEPTED', $tr->json('status'));
        $this->assertSame('IN_PREPARATION', $this->api('waiter', 'GET', "/orders/{$id}")->json('status'));
        $tr = $this->api('chef', 'POST', "/prep-tickets/{$tid}/transition", ['to' => 'READY'], ['If-Match' => $this->etag($tr)])->assertOk();
        $this->assertSame('IN_PREPARATION', $this->api('waiter', 'GET', "/orders/{$id}")->json('status')); // bar still pending

        $bid = Ids::fromBinary($bar->id);
        $bt = $this->api('barman', 'GET', "/prep-tickets/{$bid}");
        $this->api('barman', 'POST', "/prep-tickets/{$bid}/transition", ['to' => 'READY'], ['If-Match' => $this->etag($bt)])
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid'); // NEW -> READY skips too far
        $bt = $this->api('barman', 'POST', "/prep-tickets/{$bid}/transition", ['to' => 'IN_PROGRESS'], ['If-Match' => $this->etag($bt)])->assertOk();
        $this->api('barman', 'POST', "/prep-tickets/{$bid}/transition", ['to' => 'READY'], ['If-Match' => $this->etag($bt)])->assertOk();
        $order = $this->api('waiter', 'GET', "/orders/{$id}");
        $this->assertSame('READY', $order->json('status'));

        $served = $this->api('waiter', 'POST', "/orders/{$id}/serve", [], ['If-Match' => $this->etag($order)])->assertOk();
        $this->assertSame('SERVED', $served->json('status'));
        $this->assertSame(2, DB::table('prep_ticket')->where('status', 'DISPENSED')->count());
    }
}
