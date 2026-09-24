<?php

namespace Tests\Feature\Orders;

use App\Domain\Orders\Broadcast\ApprovalDecided;
use App\Domain\Orders\Broadcast\ApprovalRequested;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\DemoApi;
use Tests\TestCase;

/** The mobile demo slice against the SEEDED demo property: menu -> table order -> send -> KDS -> serve, plus a supervisor-approved void. */
class DemoFlowTest extends TestCase
{
    use DemoApi;

    /** @var array<string, string> */
    private array $tok = [];

    private function as(string $user, string $method, string $uri, array $body = [], array $headers = [])
    {
        $this->tok[$user] ??= $this->loginAs($user)['accessToken'];
        $this->flushHeaders();

        return $this->withToken($this->tok[$user])->withHeaders($headers + ['Idempotency-Key' => 'd-'.bin2hex(random_bytes(10))])->json($method, '/api/v1'.$uri, $body);
    }

    public function test_seed_is_idempotent_and_has_a_realistic_menu(): void
    {
        Artisan::call('r007:demo-seed');
        $count = fn () => [DB::table('product')->count(), DB::table('price')->count(), DB::table('product_facility')->count(), DB::table('dining_table')->count(), DB::table('kds_station')->count(), DB::table('prep_route_station')->count()];
        $first = $count();
        Artisan::call('r007:demo-seed');
        $this->assertSame($first, $count());
        $this->assertGreaterThanOrEqual(60, $first[0]);
        $this->assertGreaterThanOrEqual(30, $first[3]);
        $this->assertSame(7, $first[4]);
        // kds_station ids equal the operating-point ids that already exist
        foreach ([['MAIN_KITCHEN', 'MAIN_KITCHEN'], ['RESTAURANT', 'RESTAURANT_COUNTER'], ['POOL_BAR', 'POOL_BAR'], ['BUSH_BAR', 'BUSH_BAR']] as [$f, $c]) {
            $this->assertTrue(DB::table('kds_station')->where('id', Ids::toBinary(DemoIds::operatingPoint($f, $c)))->exists(), "{$f}/{$c}");
        }
        $this->seedDemo();
        $items = collect($this->as('wait1', 'GET', '/catalog/products?limit=200&facilityId='.DemoIds::facility('RESTAURANT'))->assertOk()->json('items'));
        $this->assertGreaterThanOrEqual(35, $items->count());
        $this->assertSame('4500.0000', $items->firstWhere('sku', 'FD-JOL-CH')['price']);
        $this->assertSame('FOOD', $items->firstWhere('sku', 'FD-JOL-CH')['kind']);
        $club = collect($this->as('wait1', 'GET', '/catalog/products?limit=200&facilityId='.DemoIds::facility('INDOOR_CLUB'))->json('items'));
        $this->assertSame('2000.0000', $club->firstWhere('sku', 'BR-STAR')['price']); // club price override
        $this->assertSame('1500.0000', $items->firstWhere('sku', 'BR-STAR')['price']);
    }

    public function test_attendant_to_kitchen_to_served_and_a_supervisor_void_on_the_demo_property(): void
    {
        $this->seedDemo();
        $rst = DemoIds::facility('RESTAURANT');
        $jol = DemoIds::of('product:FD-JOL-CH');
        $chap = DemoIds::of('product:CK-CHAP');
        $t1 = DemoIds::of('table:RESTAURANT:T1');

        $tables = $this->as('wait1', 'GET', "/tables?facilityId={$rst}&limit=200")->assertOk();
        $this->assertCount(16, $tables->json('items'));
        $this->assertContains($t1, collect($tables->json('items'))->pluck('id')->all());

        $order = $this->as('wait1', 'POST', '/orders', ['facilityId' => $rst, 'tableId' => $t1, 'channel' => 'DINE_IN', 'lines' => [
            ['productId' => $jol, 'quantity' => 2, 'notes' => 'no pepper'], ['productId' => $chap, 'quantity' => 1],
        ]])->assertStatus(201);
        $this->assertSame('11500.0000', $order->json('total'));
        $sent = $this->as('wait1', 'POST', '/orders/'.$order->json('id').'/send', [], ['If-Match' => $order->headers->get('ETag')])->assertOk();

        // food is cooked in the MAIN KITCHEN (another facility), drinks at the Restaurant bar
        $kitchen = DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN');
        $bar = DemoIds::operatingPoint('RESTAURANT', 'RESTAURANT_COUNTER');
        $board = $this->as('kitchen1', 'GET', "/kds/stations/{$kitchen}/tickets")->assertOk();
        $this->assertCount(1, $board->json('items'));
        $tk = $board->json('items.0');
        $this->assertSame('no pepper', $tk['items'][0]['notes']);
        $this->assertSame('T1', $tk['tableLabel']);
        $this->assertSame(1, DB::table('prep_ticket')->where('station_id', Ids::toBinary($bar))->count());
        $stations = $this->as('kitchen1', 'GET', "/kds/stations?facilityId={$rst}")->assertOk();
        $this->assertContains($kitchen, collect($stations->json('items'))->pluck('id')->all());

        $t = $this->as('kitchen1', 'GET', '/prep-tickets/'.$tk['id']);
        $a = $this->as('kitchen1', 'POST', '/prep-tickets/'.$tk['id'].'/transition', ['to' => 'ACCEPTED'], ['If-Match' => $t->headers->get('ETag')])->assertOk();
        $r = $this->as('kitchen1', 'POST', '/prep-tickets/'.$tk['id'].'/transition', ['to' => 'READY'], ['If-Match' => $a->headers->get('ETag')])->assertOk();
        // RFC 7807: `status` stays the HTTP status (int); the ticket's own state is `currentStatus`.
        $this->as('kitchen1', 'POST', '/prep-tickets/'.$tk['id'].'/transition', ['to' => 'ACCEPTED'], ['If-Match' => $r->headers->get('ETag')])
            ->assertStatus(409)->assertJsonPath('code', 'order_state_invalid')->assertJsonPath('status', 409)
            ->assertJsonPath('currentStatus', 'READY')->assertJsonPath('allowed', ['DISPENSED']);
        $this->assertSame('READY', $r->json('status'));
        // the supervisor works the bar station
        $btk = $this->as('supervisor1', 'GET', "/kds/stations/{$bar}/tickets")->json('items.0');
        $bt = $this->as('supervisor1', 'GET', '/prep-tickets/'.$btk['id']);
        $bi = $this->as('supervisor1', 'POST', '/prep-tickets/'.$btk['id'].'/transition', ['to' => 'IN_PROGRESS'], ['If-Match' => $bt->headers->get('ETag')])->assertOk();
        $this->as('supervisor1', 'POST', '/prep-tickets/'.$btk['id'].'/transition', ['to' => 'READY'], ['If-Match' => $bi->headers->get('ETag')])->assertOk();

        $ready = $this->as('wait1', 'GET', '/orders/'.$order->json('id'));
        $this->assertSame('READY', $ready->json('status'));
        $served = $this->as('wait1', 'POST', '/orders/'.$order->json('id').'/serve', [], ['If-Match' => $ready->headers->get('ETag')])->assertOk();
        $this->assertSame('SERVED', $served->json('status'));

        // a second order is voided through the approval queue (waiter requests, supervisor decides)
        $o2 = $this->as('wait1', 'POST', '/orders', ['facilityId' => $rst, 'tableId' => DemoIds::of('table:RESTAURANT:T2'), 'lines' => [['productId' => $jol, 'quantity' => 1]]])->assertStatus(201);
        $s2 = $this->as('wait1', 'POST', '/orders/'.$o2->json('id').'/send', [], ['If-Match' => $o2->headers->get('ETag')])->assertOk();
        $v = $this->as('wait1', 'POST', '/orders/'.$o2->json('id').'/void', ['reason' => 'Guest changed mind'], ['If-Match' => $s2->headers->get('ETag')])->assertStatus(202);
        $pending = $this->as('supervisor1', 'GET', '/approvals?scope=approvable')->assertOk();
        $this->assertSame([$v->json('approval.id')], collect($pending->json('items'))->pluck('id')->all());
        $this->as('supervisor1', 'POST', '/approvals/'.$v->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertOk();
        $this->assertSame('VOIDED', $this->as('wait1', 'GET', '/orders/'.$o2->json('id'))->json('status'));
    }

    public function test_other_facilities_route_to_their_own_bar_and_the_main_kitchen(): void
    {
        $this->seedDemo();
        $club = DemoIds::facility('INDOOR_CLUB');
        $o = $this->as('wait1', 'POST', '/orders', ['facilityId' => $club, 'tableId' => DemoIds::of('table:INDOOR_CLUB:VIP1'), 'lines' => [
            ['productId' => DemoIds::of('product:BT-MOET'), 'quantity' => 1], ['productId' => DemoIds::of('product:FD-PEP-GT'), 'quantity' => 1],
        ]])->assertStatus(201);
        $this->assertSame('68500.0000', $o->json('total')); // 65,000 bottle + 3,500 pepper soup
        $this->as('wait1', 'POST', '/orders/'.$o->json('id').'/send', [], ['If-Match' => $o->headers->get('ETag')])->assertOk();
        $stations = DB::table('prep_ticket')->pluck('station_id')->map(fn ($b) => Ids::fromBinary($b))->sort()->values()->all();
        $expect = [DemoIds::of('kds:INDOOR_CLUB:BAR'), DemoIds::operatingPoint('MAIN_KITCHEN', 'MAIN_KITCHEN')];
        sort($expect);
        $this->assertSame($expect, $stations);
    }

    public function test_device_context_is_recorded_and_approvals_reach_the_supervisors_checked_out_tablet(): void
    {
        $this->seedDemo();
        Event::fake([ApprovalRequested::class, ApprovalDecided::class]);
        $rst = DemoIds::facility('RESTAURANT');
        $waiterTablet = $this->deviceToken('TABLET_WAITER_01');
        $supTablet = DemoIds::device('TABLET_SUPERVISOR_1');
        // manager checks the supervisor's tablet out at the Restaurant
        $this->as('manager1', 'POST', "/devices/{$supTablet}/checkout", ['staffId' => DemoIds::staff('supervisor1'), 'facilityId' => $rst])->assertOk();

        $h = ['X-Device-Token' => $waiterTablet];
        $o = $this->as('wait1', 'POST', '/orders', ['facilityId' => $rst, 'tableId' => DemoIds::of('table:RESTAURANT:T3'), 'lines' => [['productId' => DemoIds::of('product:FD-JOL-CH'), 'quantity' => 1]]], $h)->assertStatus(201);
        $this->assertSame(DemoIds::device('TABLET_WAITER_01'), $o->json('deviceId'));
        $sent = $this->as('wait1', 'POST', '/orders/'.$o->json('id').'/send', [], $h + ['If-Match' => $o->headers->get('ETag')])->assertOk();
        $v = $this->as('wait1', 'POST', '/orders/'.$o->json('id').'/void', ['reason' => 'Guest left'], $h + ['If-Match' => $sent->headers->get('ETag')])->assertStatus(202);
        Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->channels === ['device.'.$supTablet] && $e->data['approval']['id'] === $v->json('approval.id'));

        $this->as('supervisor1', 'POST', '/approvals/'.$v->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertOk();
        Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->channels === ['device.'.DemoIds::device('TABLET_WAITER_01')] && $e->data['applied'] === true);
    }
}
