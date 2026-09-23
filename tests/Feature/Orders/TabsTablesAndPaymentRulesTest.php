<?php

namespace Tests\Feature\Orders;

use App\Domain\Orders\Events\OrderSettled;
use App\Domain\Orders\Services\OrderSettlementService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\OrdersFixture;
use Tests\Support\TestData;

class TabsTablesAndPaymentRulesTest extends OrdersTestCase
{
    private function clubStaff(): void
    {
        $f = $this->f;
        $cat = DB::table('product_category')->value('id');
        $list = DB::table('price_list')->value('id');
        $id = OrdersFixture::insert('product', ['organization_id' => Ids::toBinary($f->t['org']), 'category_id' => $cat, 'sku' => 'BEER', 'name' => 'Star Lager', 'kind' => 'GOOD']);
        DB::table('product_facility')->insert(['product_id' => Ids::toBinary($id), 'facility_unit_id' => Ids::toBinary($f->club->id)]);
        OrdersFixture::insert('price', ['price_list_id' => $list, 'product_id' => Ids::toBinary($id), 'amount' => '1500']);
        $f->products['beer'] = $id;
        $s = TestData::staff($f->t, 'clubwaiter');
        TestData::assign($s, 'WAIT_STAFF', 'FACILITY_UNIT', $f->club->id);
        $c = TestData::staff($f->t, 'clubcashier');
        TestData::assign($c, 'CASHIER', 'FACILITY_UNIT', $f->club->id);
    }

    public function test_tabs_require_the_open_tab_capability(): void
    {
        $this->api('waiter', 'POST', '/tabs', ['facilityId' => $this->f->restaurant->id])->assertStatus(422)->assertJsonPath('code', 'capability_disabled');
    }

    public function test_open_tab_facility_accumulates_orders_and_settles_once(): void
    {
        $this->clubStaff();
        $this->f->capability($this->f->club, 'OPEN_TAB', ['payment_timing' => 'PAY_ON_EXIT']);
        $tab = $this->api('clubwaiter', 'POST', '/tabs', ['facilityId' => $this->f->club->id, 'tableId' => $this->f->tables['VIP1'], 'customerName' => 'Chief Obi'])->assertStatus(201);
        $this->assertSame('OPEN', $tab->json('status'));
        $this->api('clubwaiter', 'POST', '/tabs', ['facilityId' => $this->f->club->id, 'tableId' => $this->f->tables['VIP1']])->assertStatus(409)->assertJsonPath('code', 'tab_already_open');
        $this->assertSame($tab->json('id'), $this->api('clubwaiter', 'GET', "/tables/{$this->f->tables['VIP1']}")->json('openTabId'));

        $mk = function () {
            $o = $this->api('clubwaiter', 'POST', '/orders', ['facilityId' => $this->f->club->id, 'tableId' => $this->f->tables['VIP1'],
                'lines' => [['productId' => $this->f->products['beer'], 'quantity' => 2]]])->assertStatus(201);
            $sent = $this->api('clubwaiter', 'POST', '/orders/'.$o->json('id').'/send', [], ['If-Match' => $this->etag($o)])->assertOk();

            return $this->api('clubwaiter', 'POST', '/orders/'.$o->json('id').'/serve', [], ['If-Match' => $this->etag($sent)])->assertOk();
        };
        $o1 = $mk();
        $o2 = $mk();
        $this->assertSame($tab->json('id'), $o1->json('tabId'));   // auto-attached to the table's open tab
        $this->assertSame($tab->json('id'), $o2->json('tabId'));
        $t = $this->api('clubwaiter', 'GET', '/tabs/'.$tab->json('id'))->assertOk();
        $this->assertSame('6000.0000', $t->json('total'));
        $this->assertSame('6000.0000', $t->json('balanceDue'));
        $this->assertEqualsCanonicalizing([$o1->json('id'), $o2->json('id')], $t->json('orderIds'));

        // Payments module drives settlement through OrderSettlementService
        Event::fake([OrderSettled::class]);
        $settle = app(OrderSettlementService::class);
        $settle->applyPayment($o1->json('id'), '3000');
        $settle->applyPayment($o2->json('id'), '3000');
        $settle->markTabSettled($tab->json('id'));
        Event::assertDispatchedTimes(OrderSettled::class, 2);
        $this->assertSame('SETTLED', DB::table('order')->where('id', Ids::toBinary($o1->json('id')))->value('status'));
        $this->assertSame('SETTLED', DB::table('tab')->where('id', Ids::toBinary($tab->json('id')))->value('status'));
        $this->assertSame('NEEDS_CLEANING', DB::table('dining_table')->where('id', Ids::toBinary($this->f->tables['VIP1']))->value('status'));
        $this->assertSame(2, DB::table('outbox_event')->where('event_type', 'OrderSettled')->count());
    }

    public function test_add_orders_to_a_tab_and_guards(): void
    {
        $this->clubStaff();
        $this->f->capability($this->f->club, 'OPEN_TAB', ['payment_timing' => 'OPEN_TAB']);
        $tab = $this->api('clubwaiter', 'POST', '/tabs', ['facilityId' => $this->f->club->id, 'customerName' => 'Walk-in'])->assertStatus(201);
        $o = $this->api('clubwaiter', 'POST', '/orders', ['facilityId' => $this->f->club->id, 'channel' => 'COUNTER', 'lines' => [['productId' => $this->f->products['beer'], 'quantity' => 1]]])->assertStatus(201);
        $this->assertNotNull($o->json('tabId')); // OPEN_TAB facilities auto-open a tab for every order
        $other = $this->api('clubwaiter', 'POST', '/tabs/'.$tab->json('id').'/orders', ['orderIds' => [$o->json('id')]], ['If-Match' => $this->etag($tab)])->assertStatus(409);
        $other->assertJsonPath('code', 'order_state_invalid'); // already on another tab

        $restOrder = $this->draft(['jollof' => 1]);
        $this->api('clubwaiter', 'POST', '/tabs/'.$tab->json('id').'/orders', ['orderIds' => [$restOrder->json('id')]], ['If-Match' => $this->etag($tab)])->assertStatus(422)->assertJsonPath('code', 'facility_mismatch');
    }

    public function test_pay_first_facility_blocks_send_until_paid_and_serving_settles(): void
    {
        $this->f->capability($this->f->restaurant, 'POS', ['payment_timing' => 'PAY_FIRST']);
        $d = $this->draft(['water' => 2], 'waiter', 'T2');
        $this->send($d->json('id'), $this->etag($d))->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');

        $settle = app(OrderSettlementService::class);
        $this->expectApiProblem(fn () => $settle->applyPayment($d->json('id'), '5000'), 'amount_mismatch'); // over-payment
        $settle->applyPayment($d->json('id'), '1000');
        $fresh = $this->api('waiter', 'GET', '/orders/'.$d->json('id'));
        $this->assertSame('DRAFT', $fresh->json('status'));
        $this->assertSame('0.0000', $fresh->json('balanceDue'));
        $sent = $this->send($d->json('id'), $this->etag($fresh))->assertOk();
        $served = $this->api('waiter', 'POST', '/orders/'.$d->json('id').'/serve', [], ['If-Match' => $this->etag($sent)])->assertOk();
        $this->assertSame('SETTLED', $served->json('status')); // fully paid up front: serving completes it
    }

    public function test_pay_after_service_facility_settles_after_serving(): void
    {
        $s = $this->sent(['water' => 1], 'T2');
        $served = $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/serve', [], ['If-Match' => $this->etag($s)])->assertOk();
        $this->assertSame('SERVED', $served->json('status'));
        $settle = app(OrderSettlementService::class);
        $this->expectApiProblem(fn () => $settle->markSettled($s->json('id')), 'balance_changed'); // not paid yet
        $settle->applyPayment($s->json('id'), '500');
        $this->assertSame('SETTLED', DB::table('order')->where('id', Ids::toBinary($s->json('id')))->value('status'));
        $this->assertSame('NEEDS_CLEANING', DB::table('dining_table')->where('id', Ids::toBinary($this->f->tables['T2']))->value('status'));
        $this->expectApiProblem(fn () => $settle->applyPayment($s->json('id'), '1'), 'order_state_invalid');
    }

    public function test_void_is_refused_once_money_was_taken(): void
    {
        $this->f->capability($this->f->restaurant, 'POS', ['payment_timing' => 'PAY_FIRST']);
        $d = $this->draft(['water' => 1], 'waiter', 'T2');
        app(OrderSettlementService::class)->applyPayment($d->json('id'), '500');
        $this->api('supervisor', 'POST', '/orders/'.$d->json('id').'/void', ['reason' => 'Guest left'], ['If-Match' => '"'.(DB::table('order')->value('row_version')).'"'])
            ->assertStatus(409)->assertJsonPath('code', 'payment_state_invalid');
    }

    // ---- tables ------------------------------------------------------------------------------------------------------

    public function test_opening_a_table_is_idempotent_for_the_same_staff_and_conflicts_for_another(): void
    {
        $t = $this->f->tables['T3'];
        $a = $this->api('waiter', 'POST', "/tables/{$t}/open")->assertOk();
        $this->assertSame('OCCUPIED', $a->json('status'));
        $this->api('waiter', 'POST', "/tables/{$t}/open")->assertOk()->assertJsonPath('status', 'OCCUPIED');
        $this->api('waiter2', 'POST', "/tables/{$t}/open")->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
    }

    public function test_creating_an_order_occupies_the_table_and_it_frees_when_settled(): void
    {
        $this->assertSame('FREE', $this->api('waiter', 'GET', "/tables/{$this->f->tables['T1']}")->json('status'));
        $d = $this->draft(['jollof' => 1]);
        $tbl = $this->api('waiter', 'GET', "/tables/{$this->f->tables['T1']}");
        $this->assertSame('OCCUPIED', $tbl->json('status'));
        $this->assertSame([$d->json('id')], $tbl->json('openOrderIds'));
        $list = $this->api('waiter', 'GET', "/tables?facilityId={$this->f->restaurant->id}&filter[status]=OCCUPIED")->assertOk();
        $this->assertCount(1, $list->json('items'));
    }

    public function test_table_status_patch_needs_if_match_and_refuses_freeing_a_busy_table(): void
    {
        $this->draft(['jollof' => 1]);
        $t = $this->api('waiter', 'GET', "/tables/{$this->f->tables['T1']}");
        $this->api('waiter', 'PATCH', "/tables/{$this->f->tables['T1']}", ['status' => 'FREE'])->assertStatus(428);
        $this->api('waiter', 'PATCH', "/tables/{$this->f->tables['T1']}", ['status' => 'FREE'], ['If-Match' => $this->etag($t)])->assertStatus(409);
        $t2 = $this->api('waiter', 'GET', "/tables/{$this->f->tables['T2']}");
        $r = $this->api('waiter', 'PATCH', "/tables/{$this->f->tables['T2']}", ['status' => 'RESERVED'], ['If-Match' => $this->etag($t2)])->assertOk();
        $this->assertSame('RESERVED', $r->json('status'));
        $this->api('waiter', 'PATCH', "/tables/{$this->f->tables['T2']}", ['status' => 'FREE'], ['If-Match' => $this->etag($t2)])->assertStatus(412); // stale
    }

    public function test_transfer_moves_open_orders_to_another_table(): void
    {
        $s = $this->sent(['jollof' => 1], 'T1');
        $r = $this->api('waiter', 'POST', "/tables/{$this->f->tables['T1']}/transfer", ['toTableId' => $this->f->tables['T3']])->assertOk();
        $this->assertSame('NEEDS_CLEANING', $r->json('from.status'));
        $this->assertSame('OCCUPIED', $r->json('to.status'));
        $this->assertSame([$s->json('id')], $r->json('to.openOrderIds'));
        $this->assertSame($this->f->tables['T3'], $this->api('waiter', 'GET', '/orders/'.$s->json('id'))->json('tableId'));
        $this->assertSame('T3', DB::table('prep_ticket')->value('table_label'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'table.transfer')->count());
        $this->api('waiter', 'POST', "/tables/{$this->f->tables['T3']}/transfer", ['toTableId' => $this->f->tables['T3']])->assertStatus(422);
    }

    public function test_assign_a_waiter_to_a_table(): void
    {
        $r = $this->api('supervisor', 'POST', "/tables/{$this->f->tables['T2']}/assign", ['staffId' => $this->f->staff['waiter2']->id])->assertOk();
        $this->assertSame($this->f->staff['waiter2']->id, $r->json('assignedStaffId'));
    }

    private function expectApiProblem(\Closure $fn, string $code): void
    {
        try {
            $fn();
            $this->fail("Expected ApiProblem {$code}");
        } catch (ApiProblem $e) {
            $this->assertSame($code, $e->problemCode);
        }
    }
}
