<?php

namespace Tests\Feature;

use App\Domain\Orders\Broadcast\ApprovalDecided;
use App\Domain\Orders\Broadcast\ApprovalRequested;
use App\Domain\Orders\Broadcast\OrderReady;
use App\Domain\Orders\Broadcast\OrderUpdated;
use App\Domain\Orders\Broadcast\PrepTicketCreated;
use App\Domain\Orders\Broadcast\PrepTicketUpdated;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\DemoApi;
use Tests\TestCase;

/**
 * The whole MVP demo (contract/api/mvp-flows.md flows A, A2, B) in-process against the seeded demo property — the PHPUnit twin of
 * scripts/smoke-mvp.sh (which drives a running node over HTTP and a real Reverb socket; here Reverb is asserted through the broadcast
 * events that the node pushes to it, dispatched after commit).
 */
class MvpSmokeTest extends TestCase
{
    use DemoApi;

    private function stock(string $item, string $location): string
    {
        return (string) DB::table('stock_balance as b')->join('inventory_item as i', 'i.id', '=', 'b.item_id')->join('stock_location as l', 'l.id', '=', 'b.location_id')
            ->where('i.name', $item)->where('l.name', $location)->value('b.qty_on_hand');
    }

    public function test_the_mvp_flow_end_to_end(): void
    {
        $this->seedDemo();
        Event::fake([PrepTicketCreated::class, PrepTicketUpdated::class, OrderUpdated::class, OrderReady::class, ApprovalRequested::class, ApprovalDecided::class]);

        $rst = DemoIds::facility('RESTAURANT');
        $reception = DemoIds::facility('RECEPTION');
        $waiterTablet = $this->deviceToken('TABLET_WAITER_01');
        $waiterDevice = DemoIds::device('TABLET_WAITER_01');
        $supTablet = DemoIds::device('TABLET_SUPERVISOR_1');

        // 1-3. device token -> PIN login -> tablet checkout (explicit device mode on the device)
        $wait = $this->api('wait1', $waiterTablet);
        $this->getJson('/api/v1/devices/'.$waiterDevice, ['X-Device-Token' => $waiterTablet])->assertOk()->assertJsonPath('mode', 'ATTENDANT')->assertJsonPath('kind', 'MOBILE_TABLET');
        $this->getJson('/api/v1/devices/'.$supTablet, ['X-Device-Token' => $this->deviceToken('TABLET_SUPERVISOR_1')])->assertOk()->assertJsonPath('mode', 'SUPERVISOR');
        $wait->post("/devices/{$waiterDevice}/checkout", ['staffId' => DemoIds::staff('wait1'), 'facilityId' => $rst])->assertOk();
        $this->api('manager1')->post("/devices/{$supTablet}/checkout", ['staffId' => DemoIds::staff('supervisor1'), 'facilityId' => $rst])->assertOk();

        // 4. cashier session
        $cashier = $this->api('cashier2', $this->deviceToken('POS_RESTAURANT'));
        $session = $cashier->post('/cash-sessions', ['facilityId' => $rst, 'openingFloat' => '5000.0000'])->assertStatus(201)->json('id');

        // 6-9. menu, tables, open table
        $items = collect($wait->get("/catalog/products?facilityId={$rst}&limit=200")->assertOk()->json('items'));
        $product = fn (string $sku) => $items->firstWhere('sku', $sku)['id'];
        $table = collect($wait->get("/tables?facilityId={$rst}&limit=200")->assertOk()->json('items'))->firstWhere('status', 'FREE');
        $wait->post("/tables/{$table['id']}/open")->assertOk();

        $beerBefore = $this->stock('Star Lager Beer 60cl', 'Restaurant Store');

        // 10-12. order + send
        $o = $wait->post('/orders', ['facilityId' => $rst, 'tableId' => $table['id'], 'channel' => 'DINE_IN', 'lines' => [
            ['productId' => $product('FD-JOL-CH'), 'quantity' => 2, 'notes' => 'no pepper'], ['productId' => $product('BR-STAR'), 'quantity' => 2],
        ]])->assertStatus(201);
        $this->assertSame('12000.0000', $o->json('total'));
        $wait->post('/orders/'.$o->json('id').'/send', [], ['If-Match' => $o->headers->get('ETag')])->assertOk()->assertJsonPath('status', 'SENT');
        Event::assertDispatched(PrepTicketCreated::class);
        Event::assertDispatched(OrderUpdated::class, fn ($e) => $e->data['order']['status'] === 'SENT');
        $this->assertSame('2.0000', bcsub($beerBefore, $this->stock('Star Lager Beer 60cl', 'Restaurant Store'), 4), 'stock decremented on send');

        // 13-15. kitchen + bar
        $kitchen = $this->api('kitchen1', $this->deviceToken('KDS_MAIN_KITCHEN'));
        $kst = DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN');
        $tk = collect($kitchen->get("/kds/stations/{$kst}/tickets")->assertOk()->json('items'))->firstWhere('orderId', $o->json('id'));
        $etag = $kitchen->get('/prep-tickets/'.$tk['id'])->headers->get('ETag');
        foreach (['ACCEPTED', 'IN_PROGRESS', 'READY'] as $to) {
            $etag = $kitchen->post('/prep-tickets/'.$tk['id'].'/transition', ['to' => $to], ['If-Match' => $etag])->assertOk()->headers->get('ETag');
        }
        $sup = $this->api('supervisor1');
        $bst = DemoIds::operatingPoint('RESTAURANT', 'RESTAURANT_COUNTER');
        $btk = collect($sup->get("/kds/stations/{$bst}/tickets")->json('items'))->firstWhere('orderId', $o->json('id'));
        $etag = $sup->get('/prep-tickets/'.$btk['id'])->headers->get('ETag');
        foreach (['IN_PROGRESS', 'READY'] as $to) {
            $etag = $sup->post('/prep-tickets/'.$btk['id'].'/transition', ['to' => $to], ['If-Match' => $etag])->assertOk()->headers->get('ETag');
        }
        Event::assertDispatched(PrepTicketUpdated::class, fn ($e) => $e->data['ticket']['status'] === 'READY');
        Event::assertDispatched(OrderReady::class, fn ($e) => $e->data['orderId'] === $o->json('id'));

        // 16. serve
        $ready = $wait->get('/orders/'.$o->json('id'))->assertJsonPath('status', 'READY');
        $wait->post('/orders/'.$o->json('id').'/serve', [], ['If-Match' => $ready->headers->get('ETag')])->assertOk()->assertJsonPath('status', 'SERVED');

        // A2. void needs supervisor approval; stock restored
        $t2 = collect($wait->get("/tables?facilityId={$rst}&limit=200")->json('items'))->firstWhere('status', 'FREE');
        $wait->post("/tables/{$t2['id']}/open")->assertOk();
        $o2 = $wait->post('/orders', ['facilityId' => $rst, 'tableId' => $t2['id'], 'lines' => [['productId' => $product('BR-STAR'), 'quantity' => 3]]])->assertStatus(201);
        $s2 = $wait->post('/orders/'.$o2->json('id').'/send', [], ['If-Match' => $o2->headers->get('ETag')])->assertOk();
        $v = $wait->post('/orders/'.$o2->json('id').'/void', ['reason' => 'Guest changed mind'], ['If-Match' => $s2->headers->get('ETag')])->assertStatus(202);
        Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->channels === ['device.'.$supTablet]);
        $sup->post('/approvals/'.$v->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertOk();
        $wait->get('/orders/'.$o2->json('id'))->assertJsonPath('status', 'VOIDED');
        Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->channels === ['device.'.$waiterDevice]);
        $this->assertSame('2.0000', bcsub($beerBefore, $this->stock('Star Lager Beer 60cl', 'Restaurant Store'), 4), 'void restored its stock');

        // 17-19. tab, split cash + transfer settlement, receipt
        $tab = $wait->post('/tabs', ['facilityId' => $rst, 'tableId' => $table['id']])->assertStatus(201);
        $wait->post('/tabs/'.$tab->json('id').'/orders', ['orderIds' => [$o->json('id')]], ['If-Match' => $tab->headers->get('ETag')])->assertOk();
        $paid = $cashier->post('/tabs/'.$tab->json('id').'/settle', ['cashSessionId' => $session, 'tenders' => [
            ['tenderType' => 'CASH', 'amount' => '7000.0000', 'tendered' => '10000.0000'], ['tenderType' => 'TRANSFER', 'amount' => '5000.0000', 'reference' => 'T-'.Ids::uuid7()],
        ]])->assertOk();
        $paid->assertJsonPath('changeDue', '3000.0000');
        $this->assertCount(2, $paid->json('payments'));
        $receipt = $cashier->get('/receipts/'.$paid->json('receiptId'))->assertOk()->getContent();
        $this->assertStringContainsString('TRANSFER', $receipt);
        $wait->get('/orders/'.$o->json('id'))->assertJsonPath('status', 'SETTLED');

        // Flow B: Reception hold -> order lines -> pay -> QR
        $rec = $this->api('cashier1', $this->deviceToken('TABLET_WAITER_02'));
        $recDevice = DemoIds::device('TABLET_WAITER_02');
        $rec->post("/devices/{$recDevice}/checkout", ['staffId' => DemoIds::staff('cashier1'), 'facilityId' => $reception])->assertOk();
        $recSession = $rec->post('/cash-sessions', ['facilityId' => $reception, 'openingFloat' => '0.0000'])->assertStatus(201)->json('id');
        $court = DemoIds::resource('LAWN_TENNIS', 'COURT_1');
        $from = now('UTC')->addDay()->startOfDay();
        $slot = collect($rec->get("/bookings/resources/{$court}/availability?from=".$from->toIso8601ZuluString().'&to='.$from->addDay()->toIso8601ZuluString())->assertOk()->json('slots'))->firstWhere('available', true);
        $hold = $rec->post('/bookings/hold', ['resourceId' => $court, 'start' => $slot['start'], 'end' => $slot['end'], 'customer' => ['name' => 'Chinedu Eze']])->assertStatus(201);
        $this->assertSame('HELD', $hold->json('status'));
        $rec->post('/bookings/hold', ['resourceId' => $court, 'start' => $slot['start'], 'end' => $slot['end']])->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
        $cat = collect($rec->get("/catalog/products?facilityId={$reception}&limit=200")->json('items'));
        $order = $rec->post('/orders', ['facilityId' => $reception, 'channel' => 'COUNTER', 'customerName' => 'Chinedu Eze', 'lines' => [
            ['productId' => $cat->firstWhere('sku', 'FEE-TENNIS')['id'], 'quantity' => 1], ['productId' => $cat->firstWhere('sku', 'RENTAL-RACKET')['id'], 'quantity' => 2],
        ]])->assertStatus(201);
        $attached = $rec->post('/bookings/'.$hold->json('id').'/order', ['orderId' => $order->json('id')], ['If-Match' => $hold->headers->get('ETag')])->assertOk();
        $confirmed = $rec->post('/bookings/'.$hold->json('id').'/confirm', ['cashSessionId' => $recSession, 'tenders' => [['tenderType' => 'CASH', 'amount' => $order->json('total'), 'tendered' => $order->json('total')]]], ['If-Match' => $attached->headers->get('ETag')])->assertOk();
        $this->assertSame('CONFIRMED', $confirmed->json('status'));
        $ent = $rec->get('/entitlements/'.$confirmed->json('entitlementId'))->assertOk();
        $qr = $ent->json('qrToken');
        $this->assertSame(['ACCESS', 'RENTAL'], collect($ent->json('items'))->pluck('kind')->all());
        DB::table('entitlement_item')->where('entitlement_id', Ids::toBinary($ent->json('id')))->update(['valid_from' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'valid_until' => now('UTC')->addHours(2)->format('Y-m-d H:i:s.u')]);

        // entrance: VALID then USED
        $entrance = DemoIds::device('TABLET_SPORTS_ENTRANCE');
        $this->api('manager1')->post("/devices/{$entrance}/checkout", ['staffId' => DemoIds::staff('supervisor1'), 'facilityId' => DemoIds::facility('SPORTS_ARENA')])->assertOk();
        $gate = $this->api('supervisor1', $this->deviceToken('TABLET_SPORTS_ENTRANCE'));
        $gate->post("/entitlement-tokens/{$qr}/redeem", ['action' => 'ENTRY'])->assertOk()->assertJsonPath('result', 'VALID');
        $gate->post("/entitlement-tokens/{$qr}/redeem", ['action' => 'ENTRY'])->assertOk()->assertJsonPath('result', 'USED');

        // store: release + return the racket
        $storeDev = DemoIds::device('TABLET_SPORTS_STORE');
        $this->api('manager1')->post("/devices/{$storeDev}/checkout", ['staffId' => DemoIds::staff('storekeeper1'), 'facilityId' => DemoIds::facility('SPORTS_STORE')])->assertOk();
        $store = $this->api('storekeeper1', $this->deviceToken('TABLET_SPORTS_STORE'));
        $rental = collect($store->get("/entitlement-tokens/{$qr}")->assertOk()->json('items'))->firstWhere('kind', 'RENTAL');
        $store->post('/entitlements/'.$ent->json('id').'/release', ['itemIds' => [$rental['id']]])->assertOk();
        $store->post('/entitlements/'.$ent->json('id').'/return', ['itemIds' => [$rental['id']], 'condition' => 'OK'])->assertOk()
            ->assertJsonPath('items.1.rentalStatus', 'RETURNED');

        // inventory reconciles; outbox events exist; audit chain verifies
        $this->assertSame(0, Artisan::call('r007:inventory:reconcile'));
        foreach (['OrderCreated', 'OrderVoided', 'OrderSettled', 'PaymentCompleted', 'TabSettled', 'StockConsumed', 'BookingConfirmedLocally', 'EntitlementIssued', 'TicketRedeemed', 'RentalReleased', 'RentalReturned'] as $event) {
            $this->assertGreaterThan(0, DB::table('outbox_event')->where('event_type', $event)->count(), "outbox: {$event}");
        }
        $this->assertTrue(Audit::verifyChain()->valid);
    }
}
