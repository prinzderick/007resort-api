<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Services\ReconciliationService;
use App\Domain\Inventory\Support\ConsumptionLine;
use App\Domain\Orders\Events\OrderSettled;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Orders\OrdersTestCase;
use Tests\Support\InventoryData;
use Tests\Support\TestData;

/** Orders <-> Inventory over Catalog's product_stock_link: consumption timing, insufficient stock, voids, availability, approvals. */
class OrderInventoryTest extends OrdersTestCase
{
    private $store;

    private $rice;

    private $chicken;

    private $chapmanMix;

    protected function setUp(): void
    {
        parent::setUp();
        $t = $this->f->t;
        $this->store = InventoryData::location($t, 'Restaurant Store', 'FACILITY_STORE', $this->f->restaurant->id);
        $this->rice = InventoryData::item($t, 'RICE', 'Rice', 'kg');
        $this->chicken = InventoryData::item($t, 'CHKN', 'Chicken', 'kg');
        $this->chapmanMix = InventoryData::item($t, 'CHAP', 'Chapman mix', 'bottle');
        // jollof = 0.5 kg rice + 0.25 kg chicken per portion; chapman = 1 mix
        $this->link('jollof', $this->rice->id, '0.5');
        $this->link('jollof', $this->chicken->id, '0.25');
        $this->link('chapman', $this->chapmanMix->id, '1');
        DB::table('product')->update(['track_stock' => 1]);
    }

    private function link(string $product, string $itemId, string $perUnit): void
    {
        DB::table('product_stock_link')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'product_id' => Ids::toBinary($this->f->products[$product]), 'stock_item_id' => Ids::toBinary($itemId), 'quantity_per_unit' => $perUnit,
        ]);
    }

    private function onHand(string $itemId): string
    {
        return InventoryData::onHand($this->store->id, $itemId);
    }

    public function test_sending_an_order_consumes_linked_stock_per_portion_in_the_same_transaction(): void
    {
        InventoryData::stock($this->store->id, $this->rice->id, '10');
        InventoryData::stock($this->store->id, $this->chicken->id, '10');
        InventoryData::stock($this->store->id, $this->chapmanMix->id, '10');
        $r = $this->draft(['jollof' => 3, 'chapman' => 2, 'water' => 1]); // water has no stock link
        $orderId = $r->json('id');
        $this->assertSame('10.0000', $this->onHand($this->rice->id), 'drafting reserves nothing');

        $this->send($orderId, $this->etag($r))->assertOk()->assertJsonPath('status', 'SENT');

        $this->assertSame('8.5000', $this->onHand($this->rice->id));      // 3 x 0.5
        $this->assertSame('9.2500', $this->onHand($this->chicken->id));   // 3 x 0.25
        $this->assertSame('8.0000', $this->onHand($this->chapmanMix->id)); // 2 x 1
        $sales = DB::table('stock_movement')->where('reason', 'SALE')->get();
        $this->assertCount(3, $sales);
        foreach ($sales as $m) {
            $this->assertSame('order', $m->reference_type);
            $this->assertSame(Ids::toBinary($orderId), $m->reference_id);
            $this->assertNotNull($m->reference_line_id);
            $this->assertNotNull($m->actor_staff_id);
        }
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StockConsumed')->count());
    }

    public function test_insufficient_stock_rejects_the_send_and_leaves_no_trace(): void
    {
        InventoryData::stock($this->store->id, $this->rice->id, '1');        // enough for 2 portions
        InventoryData::stock($this->store->id, $this->chicken->id, '10');
        $r = $this->draft(['jollof' => 3]);                                   // needs 1.5 kg rice
        $orderId = $r->json('id');

        $res = $this->send($orderId, $this->etag($r))->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $res->assertJsonPath('meta.itemId', $this->rice->id)->assertJsonPath('meta.productId', $this->f->products['jollof'])->assertJsonPath('meta.availableQuantity', '1.0000');

        $this->assertSame('DRAFT', $this->api('waiter', 'GET', "/orders/{$orderId}")->json('status'), 'the order stays a draft the waiter can fix');
        $this->assertSame(0, DB::table('prep_ticket')->count(), 'no prep ticket for a rejected send');
        $this->assertSame('1.0000', $this->onHand($this->rice->id));
        $this->assertSame('10.0000', $this->onHand($this->chicken->id), 'the chicken deduction that fit was rolled back too');
        $this->assertSame(0, DB::table('stock_movement')->where('reason', 'SALE')->count());

        // the waiter reduces the quantity? (2 portions fit) — simulate by restocking and re-sending
        InventoryData::stock($this->store->id, $this->rice->id, '1');
        $this->send($orderId, $this->etag($this->api('waiter', 'GET', "/orders/{$orderId}")))->assertOk();
        $this->assertSame('0.5000', $this->onHand($this->rice->id));
    }

    public function test_voiding_a_sent_order_restores_stock_exactly_once(): void
    {
        InventoryData::stock($this->store->id, $this->rice->id, '10');
        InventoryData::stock($this->store->id, $this->chicken->id, '10');
        $r = $this->draft(['jollof' => 4]);
        $orderId = $r->json('id');
        $sent = $this->send($orderId, $this->etag($r))->assertOk();
        $this->assertSame('8.0000', $this->onHand($this->rice->id));

        $this->api('supervisor', 'POST', "/orders/{$orderId}/void", ['reason' => 'guest left'], ['If-Match' => $this->etag($sent)])->assertOk()->assertJsonPath('status', 'VOIDED');

        $this->assertSame('10.0000', $this->onHand($this->rice->id));
        $this->assertSame('10.0000', $this->onHand($this->chicken->id));
        $this->assertSame(2, DB::table('stock_movement')->where('reason', 'SALE_RETURN')->count());
        $this->assertSame(0, app(ReconciliationService::class)->drift() === [] ? 0 : 1);
    }

    public function test_settle_timing_rule_defers_consumption_until_the_order_settles(): void
    {
        $this->f->capability($this->f->restaurant, 'INVENTORY', ['stock_consumption_timing' => 'SETTLE']);
        InventoryData::stock($this->store->id, $this->chapmanMix->id, '5');
        $r = $this->draft(['chapman' => 2]);
        $orderId = $r->json('id');

        $this->send($orderId, $this->etag($r))->assertOk();
        $this->assertSame('5.0000', $this->onHand($this->chapmanMix->id), 'SEND does not consume under SETTLE timing');

        event(new OrderSettled($orderId, $this->f->restaurant->id, '5000.0000', '5000.0000'));
        $this->assertSame('3.0000', $this->onHand($this->chapmanMix->id));
        event(new OrderSettled($orderId, $this->f->restaurant->id, '5000.0000', '5000.0000')); // re-delivered
        $this->assertSame('3.0000', $this->onHand($this->chapmanMix->id), 'idempotent per line');
    }

    public function test_send_timing_ignores_the_settled_event(): void
    {
        InventoryData::stock($this->store->id, $this->chapmanMix->id, '5');
        $r = $this->draft(['chapman' => 1]);
        $this->send($r->json('id'), $this->etag($r))->assertOk();
        event(new OrderSettled($r->json('id'), $this->f->restaurant->id, '2500.0000', '2500.0000'));
        $this->assertSame('4.0000', $this->onHand($this->chapmanMix->id));
    }

    public function test_settle_timing_rejects_at_settlement_when_stock_ran_out_meanwhile(): void
    {
        $this->f->capability($this->f->restaurant, 'INVENTORY', ['stock_consumption_timing' => 'SETTLE']);
        InventoryData::stock($this->store->id, $this->chapmanMix->id, '1');
        $r = $this->draft(['chapman' => 1]);
        $this->send($r->json('id'), $this->etag($r))->assertOk();
        // meanwhile the last bottle is sold elsewhere (a walk-up sale on another order)
        app(InventoryConsumption::class)->consume(
            $this->f->restaurant->id, [new ConsumptionLine($this->chapmanMix->id, '1', Ids::uuid7())], 'order', Ids::uuid7());

        try {
            event(new OrderSettled($r->json('id'), $this->f->restaurant->id, '2500.0000', '2500.0000'));
            $this->fail('settlement must fail with insufficient_stock');
        } catch (ApiProblem $e) {
            $this->assertSame('insufficient_stock', $e->problemCode);
        }
    }

    public function test_catalog_availability_reflects_stock_and_flips_to_out_of_stock(): void
    {
        InventoryData::stock($this->store->id, $this->rice->id, '1');       // 2 portions of rice
        InventoryData::stock($this->store->id, $this->chicken->id, '0.5');  // 2 portions of chicken -> limiting item
        DB::table('stock_balance')->where('item_id', Ids::toBinary($this->chicken->id))->update(['qty_on_hand' => '0.75']); // 3 portions
        $qs = '?facilityId='.$this->f->restaurant->id.'&filter[productId]='.$this->f->products['jollof'];
        $row = $this->api('waiter', 'GET', '/catalog/availability'.$qs)->assertOk()->json('items.0');
        $this->assertTrue($row['available']);
        $this->assertSame('2.0000', $row['quantityOnHand']); // min(1/0.5, 0.75/0.25)

        DB::table('stock_balance')->where('item_id', Ids::toBinary($this->rice->id))->update(['qty_on_hand' => 0]);
        $row = $this->api('waiter', 'GET', '/catalog/availability'.$qs)->json('items.0');
        $this->assertFalse($row['available']);
        $this->assertSame('OUT_OF_STOCK', $row['reason']);
    }

    public function test_product_links_must_point_at_real_inventory_items(): void
    {
        $this->expectException(QueryException::class);
        DB::table('product_stock_link')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'product_id' => Ids::toBinary($this->f->products['suya']), 'stock_item_id' => Ids::toBinary(Ids::uuid7()), 'quantity_per_unit' => 1]);
    }

    public function test_inventory_adjustments_flow_through_the_orders_approvals_api(): void
    {
        InventoryData::stock($this->store->id, $this->rice->id, '10');
        $keeper = TestData::staff($this->f->t, 'keeper');
        TestData::assign($keeper, 'STOREKEEPER', 'FACILITY_UNIT', $this->f->restaurant->id);

        $r = $this->api('keeper', 'POST', '/inventory/adjustments', ['locationId' => $this->store->id, 'itemId' => $this->rice->id, 'quantityDelta' => '-2', 'reason' => 'DAMAGE', 'note' => 'wet bag'])
            ->assertStatus(202)->assertJsonPath('status', 'PENDING_APPROVAL')->assertJsonPath('approval.action', 'inventory.adjustment')
            ->assertJsonPath('approval.entityType', 'StockAdjustment')->assertJsonPath('approval.requiredPermission', 'inventory.adjustment.approve');
        $approvalId = $r->json('approval.id');
        $this->assertSame($this->f->restaurant->id, $r->json('approval.facilityId'));

        // supervisor sees it in their approvable list and decides through the generic approvals API
        $list = $this->api('supervisor', 'GET', '/approvals?scope=approvable')->assertOk()->json('items');
        $this->assertContains($approvalId, array_column($list, 'id'));
        $this->api('keeper', 'POST', "/approvals/{$approvalId}/decision", ['decision' => 'APPROVE'])->assertStatus(403);
        $this->api('supervisor', 'POST', "/approvals/{$approvalId}/decision", ['decision' => 'APPROVE', 'note' => 'ok'])->assertOk()->assertJsonPath('status', 'APPROVED');

        $this->assertSame('8.0000', $this->onHand($this->rice->id));
        $m = DB::table('stock_movement')->where('reason', 'ADJUSTMENT')->first();
        $this->assertSame(Ids::toBinary($approvalId), $m->approval_id);
        $this->assertSame('POSTED', DB::table('stock_adjustment')->value('status'));
    }

    public function test_rejected_and_cancelled_approvals_close_the_adjustment_without_touching_stock(): void
    {
        InventoryData::stock($this->store->id, $this->rice->id, '10');
        $keeper = TestData::staff($this->f->t, 'keeper');
        TestData::assign($keeper, 'STOREKEEPER', 'FACILITY_UNIT', $this->f->restaurant->id);
        $mk = fn () => $this->api('keeper', 'POST', '/inventory/adjustments', ['locationId' => $this->store->id, 'itemId' => $this->rice->id, 'quantityDelta' => '-1', 'reason' => 'THEFT', 'note' => 'missing'])->json('approval.id');

        $a1 = $mk();
        $this->api('supervisor', 'POST', "/approvals/{$a1}/decision", ['decision' => 'REJECT', 'note' => 'no'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $a2 = $mk();
        $this->api('keeper', 'POST', "/approvals/{$a2}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');

        $this->assertEqualsCanonicalizing(['REJECTED', 'CANCELLED'], DB::table('stock_adjustment')->pluck('status')->all());
        $this->assertSame('10.0000', $this->onHand($this->rice->id));
        $this->assertSame(0, DB::table('stock_movement')->where('reason', 'ADJUSTMENT')->count());
    }

    public function test_main_store_adjustment_needs_a_site_wide_approver(): void
    {
        $main = InventoryData::location($this->f->t, 'Main Store', 'MAIN_STORE', null, false, false);
        InventoryData::stock($main->id, $this->rice->id, '10');
        $keeper = TestData::staff($this->f->t, 'mainkeeper');
        TestData::assign($keeper, 'STOREKEEPER', 'SITE');

        $r = $this->api('mainkeeper', 'POST', '/inventory/adjustments', ['locationId' => $main->id, 'itemId' => $this->rice->id, 'quantityDelta' => '-3', 'reason' => 'EXPIRY', 'note' => 'expired sacks'])->assertStatus(202);
        $id = $r->json('approval.id');
        // a facility-level supervisor does not cover the site's root facility
        $this->api('supervisor', 'POST', "/approvals/{$id}/decision", ['decision' => 'APPROVE'])->assertStatus(403);
        $this->api('manager', 'POST', "/approvals/{$id}/decision", ['decision' => 'APPROVE'])->assertOk();
        $this->assertSame('7.0000', InventoryData::onHand($main->id, $this->rice->id));
    }
}
