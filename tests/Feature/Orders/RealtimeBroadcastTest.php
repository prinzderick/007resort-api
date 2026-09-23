<?php

namespace Tests\Feature\Orders;

use App\Domain\Hospitality\Broadcasting\Channels;
use App\Domain\Identity\Models\Staff;
use App\Domain\Orders\Broadcast\ApprovalDecided;
use App\Domain\Orders\Broadcast\ApprovalRequested;
use App\Domain\Orders\Broadcast\OrderReady;
use App\Domain\Orders\Broadcast\OrderUpdated;
use App\Domain\Orders\Broadcast\PrepTicketCreated;
use App\Domain\Orders\Broadcast\PrepTicketUpdated;
use App\Domain\Orders\Broadcast\TableUpdated;
use App\Domain\Orders\Events\OrderSent;
use App\Domain\Orders\Events\OrderVoided;
use App\Support\Ids;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\OrdersFixture;

/** api/realtime.md: events, channels, envelope; dispatched after commit; domain events for other modules. */
class RealtimeBroadcastTest extends OrdersTestCase
{
    public function test_send_broadcasts_prep_tickets_to_each_station_and_order_updated_to_the_facility(): void
    {
        Event::fake([PrepTicketCreated::class, OrderUpdated::class, TableUpdated::class, OrderSent::class]);
        $s = $this->sent(['jollof' => 1, 'chapman' => 1], 'T1');

        Event::assertDispatched(TableUpdated::class, fn ($e) => $e->data['table']['status'] === 'OCCUPIED' && $e->channels === ["facility.{$this->f->restaurant->id}.orders"]);
        Event::assertDispatchedTimes(PrepTicketCreated::class, 2);
        $byStation = collect(Event::dispatched(PrepTicketCreated::class))->map(fn ($a) => $a[0])->keyBy(fn ($e) => $e->channels[0]);
        $k = $byStation["kds.station.{$this->f->kitchenStation}"];
        $this->assertSame('K-001', $k->data['ticket']['number']);
        $this->assertSame('NEW', $k->data['ticket']['status']);
        $this->assertSame($s->json('id'), $k->data['ticket']['orderId']);
        $this->assertSame(1, $k->data['ticket']['rowVersion']);
        $this->assertTrue($byStation->has("kds.station.{$this->f->barStation}"));
        Event::assertDispatched(OrderUpdated::class, fn ($e) => $e->data['order']['status'] === 'SENT' && $e->data['rowVersion'] === 2 && in_array('lines', $e->data['changed'], true));
        // plain-data domain event for Inventory
        Event::assertDispatched(OrderSent::class, fn ($e) => $e->orderId === $s->json('id') && $e->facilityUnitId === $this->f->restaurant->id
            && count($e->lines) === 2 && $e->lines[0]['qty'] === 1 && isset($e->lines[0]['productId'], $e->lines[0]['lineId']));
    }

    public function test_transitions_broadcast_ticket_updates_and_order_ready(): void
    {
        $this->sent(['jollof' => 1], 'T1');
        $tid = Ids::fromBinary(DB::table('prep_ticket')->value('id'));
        Event::fake([PrepTicketUpdated::class, OrderReady::class, OrderUpdated::class]);
        $t = $this->api('chef', 'GET', "/prep-tickets/{$tid}");
        $a = $this->api('chef', 'POST', "/prep-tickets/{$tid}/transition", ['to' => 'ACCEPTED'], ['If-Match' => $this->etag($t)])->assertOk();
        Event::assertDispatched(PrepTicketUpdated::class, fn ($e) => $e->data['previousStatus'] === 'NEW' && $e->data['ticket']['status'] === 'ACCEPTED');
        Event::assertNotDispatched(OrderReady::class);
        Event::assertDispatched(OrderUpdated::class, fn ($e) => $e->data['order']['status'] === 'IN_PREPARATION');
        $this->api('chef', 'POST', "/prep-tickets/{$tid}/transition", ['to' => 'READY'], ['If-Match' => $this->etag($a)])->assertOk();
        Event::assertDispatched(OrderReady::class, fn ($e) => $e->data['tableLabel'] === 'T1' && $e->data['stationIds'] === [$this->f->kitchenStation] && isset($e->data['orderNumber'], $e->data['waiterStaffId']));
    }

    public function test_events_are_broadcast_on_private_channels_with_the_documented_envelope(): void
    {
        $e = new PrepTicketCreated(['kds.station.abc'], ['ticket' => ['id' => 'x']]);
        $this->assertInstanceOf(ShouldBroadcastNow::class, $e);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $e); // never before the DB commit
        $this->assertEquals([new PrivateChannel('kds.station.abc')], $e->broadcastOn());
        $this->assertSame('private-kds.station.abc', $e->broadcastOn()[0]->name);
        $this->assertSame('prep-ticket.created', $e->broadcastAs());
        $w = $e->broadcastWith();
        $this->assertSame(['eventId', 'occurredAt', 'correlationId', 'data'], array_keys($w));
        $this->assertTrue(Ids::isUuid($w['eventId']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $w['occurredAt']);
        $this->assertSame(['ticket' => ['id' => 'x']], $w['data']);
        foreach ([[PrepTicketUpdated::class, 'prep-ticket.updated'], [OrderUpdated::class, 'order.updated'], [OrderReady::class, 'order.ready'], [TableUpdated::class, 'table.updated'],
            [ApprovalRequested::class, 'approval.requested'], [ApprovalDecided::class, 'approval.decided']] as [$cls, $name]) {
            $this->assertSame($name, $cls::wireName());
        }
    }

    public function test_approval_request_and_decision_are_pushed_to_the_right_devices(): void
    {
        $waiterDevice = $this->device($this->f->staff['waiter'], 'TABLET');
        $supDevice = $this->device($this->f->staff['supervisor'], 'TABLET');
        $s = $this->sent(['jollof' => 1]);
        Event::fake([ApprovalRequested::class, ApprovalDecided::class, OrderVoided::class]);
        $r = $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'Guest left'], ['If-Match' => $this->etag($s)])->assertStatus(202);
        Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->channels === ["device.{$supDevice}"] && $e->data['approval']['id'] === $r->json('approval.id') && $e->data['approval']['status'] === 'PENDING');

        DB::table('approval')->where('id', Ids::toBinary($r->json('approval.id')))->update(['requested_device_id' => Ids::toBinary($waiterDevice)]);
        $this->api('supervisor', 'POST', '/approvals/'.$r->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertOk();
        Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->channels === ["device.{$waiterDevice}"] && $e->data['applied'] === true && $e->data['orderId'] === $s->json('id') && $e->data['approval']['status'] === 'APPROVED');
        Event::assertDispatched(OrderVoided::class, fn ($e) => $e->orderId === $s->json('id') && $e->lines[0]['sent'] === true && $e->approvalId === $r->json('approval.id'));
    }

    public function test_void_cancels_tickets_and_pushes_updates_to_the_stations(): void
    {
        $s = $this->sent(['jollof' => 1, 'chapman' => 1]);
        Event::fake([PrepTicketUpdated::class, OrderUpdated::class, TableUpdated::class]);
        $this->api('supervisor', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'Kitchen error'], ['If-Match' => $this->etag($s)])->assertOk();
        Event::assertDispatchedTimes(PrepTicketUpdated::class, 2);
        Event::assertDispatched(PrepTicketUpdated::class, fn ($e) => $e->data['ticket']['status'] === 'CANCELLED' && $e->data['previousStatus'] === 'NEW');
        Event::assertDispatched(OrderUpdated::class, fn ($e) => $e->data['order']['status'] === 'VOIDED');
        Event::assertDispatched(TableUpdated::class, fn ($e) => $e->data['table']['status'] === 'FREE');
    }

    public function test_failed_requests_broadcast_nothing(): void
    {
        Event::fake([PrepTicketCreated::class, OrderUpdated::class, TableUpdated::class]);
        $d = $this->draft(['jollof' => 1]);
        Event::fake([PrepTicketCreated::class, OrderUpdated::class, TableUpdated::class]); // reset counters
        $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/send', [], ['If-Match' => '"99"'])->assertStatus(412);
        Event::assertNotDispatched(PrepTicketCreated::class);
        Event::assertNotDispatched(OrderUpdated::class);
    }

    // ---- channel authorisation ---------------------------------------------------------------------------------------

    private function device(Staff $staff, string $type, ?string $facility = null): string
    {
        $id = OrdersFixture::insert('device', [
            'organization_id' => Ids::toBinary($this->f->t['org']), 'site_id' => Ids::toBinary($this->f->t['site']), 'facility_unit_id' => Ids::toBinary($facility ?? $this->f->restaurant->id),
            'device_type' => $type, 'name' => $type.'-'.substr(md5((string) microtime(true)), 0, 4),
        ]);
        // a live session of the staff member on that device
        $account = DB::table('user_account')->where('staff_id', Ids::toBinary($staff->id))->value('id');
        OrdersFixture::insert('session', [
            'user_account_id' => $account, 'device_id' => Ids::toBinary($id), 'refresh_token_hash' => hash('sha256', $id), 'expires_at' => now('UTC')->addDay()->format('Y-m-d H:i:s.u'),
        ]);

        return $id;
    }

    public function test_the_channels_our_events_use_are_authorised_by_the_realtime_registry(): void
    {
        // channel rules live in Devices (App\Support\Realtime\Channels); orders/KDS only publish to these patterns
        $patterns = \App\Support\Realtime\Channels::patterns();
        foreach (['kds.station.{stationId}', 'facility.{facilityId}.orders', 'device.{deviceId}'] as $p) {
            $this->assertContains($p, $patterns);
        }
    }
}
