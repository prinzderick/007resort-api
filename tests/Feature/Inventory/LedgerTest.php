<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Services\StockDocuments;
use App\Domain\Inventory\Support\ConsumptionLine;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryData;
use Tests\Support\TestData;
use Tests\TestCase;

/** Service-level ledger rules (single connection; real MySQL). Concurrency lives in InventoryConcurrencyTest. */
class LedgerTest extends TestCase
{
    private array $t;

    private $main;

    private $bar;

    private $beer;

    private $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
        $this->main = InventoryData::location($this->t, 'Main Store', 'MAIN_STORE', null, false, false);
        $barFacility = TestData::facility($this->t, 'bar');
        $this->bar = InventoryData::location($this->t, 'Bar Store', 'BAR', $barFacility->id);
        $this->beer = InventoryData::item($this->t, 'BEER', 'Beer');
        $this->wine = InventoryData::item($this->t, 'WINE', 'Wine');
    }

    public function test_purchase_receipt_credits_the_store_and_writes_ledger_audit_outbox(): void
    {
        $doc = app(StockDocuments::class)->receive([
            'locationId' => $this->main->id, 'supplierName' => 'Acme Ltd', 'supplierInvoice' => 'INV-1',
            'lines' => [['itemId' => $this->beer->id, 'quantity' => '24', 'unitCost' => '650.5']],
        ]);

        $this->assertSame('PURCHASE_RECEIPT', $doc['kind']);
        $this->assertSame('POSTED', $doc['status']);
        $this->assertSame('24.0000', InventoryData::onHand($this->main->id, $this->beer->id));
        $m = DB::table('stock_movement')->first();
        $this->assertSame('RECEIPT', $m->reason);
        $this->assertSame('24.0000', $m->qty_delta);
        $this->assertSame('24.0000', $m->balance_after);
        $this->assertSame('650.5000', $m->unit_cost);
        $this->assertSame('purchase_receipt', $m->reference_type);
        $this->assertSame(1, DB::table('supplier')->where('name', 'Acme Ltd')->count());
        $this->assertSame('15612.0000', DB::table('purchase_receipt')->value('total_cost'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inventory.receipt')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StockReceived')->count());
        // a second receipt from the same supplier name reuses the supplier
        app(StockDocuments::class)->receive(['locationId' => $this->main->id, 'supplierName' => 'acme ltd', 'lines' => [['itemId' => $this->beer->id, 'quantity' => '1']]]);
        $this->assertSame(1, DB::table('supplier')->count());
    }

    public function test_transfer_posts_paired_out_in_legs_with_counterparts(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '10');

        $doc = app(StockDocuments::class)->transfer(['fromLocationId' => $this->main->id, 'toLocationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '4']]]);

        $this->assertSame('6.0000', InventoryData::onHand($this->main->id, $this->beer->id));
        $this->assertSame('4.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $legs = DB::table('stock_movement')->where('reference_type', 'stock_transfer')->orderBy('qty_delta')->get();
        $this->assertCount(2, $legs);
        $this->assertSame(['TRANSFER_OUT', '-4.0000'], [$legs[0]->reason, $legs[0]->qty_delta]);
        $this->assertSame(['TRANSFER_IN', '4.0000'], [$legs[1]->reason, $legs[1]->qty_delta]);
        $this->assertSame(Ids::toBinary($this->bar->id), $legs[0]->counterpart_location_id);
        $this->assertSame(Ids::toBinary($this->main->id), $legs[1]->counterpart_location_id);
        $this->assertCount(2, $doc['lines']);
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StockTransferred')->count());
    }

    public function test_transfer_failing_mid_way_leaves_no_partial_movement(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '10');
        InventoryData::stock($this->main->id, $this->wine->id, '1');
        $before = DB::table('stock_movement')->count();
        $audit = DB::table('audit_log')->count();

        try {
            app(StockDocuments::class)->transfer(['fromLocationId' => $this->main->id, 'toLocationId' => $this->bar->id, 'lines' => [
                ['itemId' => $this->beer->id, 'quantity' => '5'],   // fine
                ['itemId' => $this->wine->id, 'quantity' => '2'],   // only 1 on hand -> fails after beer was already moved
            ]]);
            $this->fail('expected insufficient_stock');
        } catch (ApiProblem $e) {
            $this->assertSame('insufficient_stock', $e->problemCode);
            $this->assertSame(409, $e->status);
            $this->assertSame($this->wine->id, $e->extensions['meta']['itemId']);
        }

        $this->assertSame($before, DB::table('stock_movement')->count(), 'no ledger legs survive');
        $this->assertSame('10.0000', InventoryData::onHand($this->main->id, $this->beer->id));
        $this->assertSame('0.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame(0, DB::table('stock_transfer')->count(), 'no transfer document survives');
        $this->assertSame($audit, DB::table('audit_log')->count());
        $this->assertSame(0, DB::table('outbox_event')->where('event_type', 'StockTransferred')->count());
    }

    public function test_deduction_below_zero_is_refused_unless_the_location_allows_negative(): void
    {
        $sales = app(InventoryConsumption::class);
        $facility = DB::table('facility_unit')->first();
        $facilityId = Ids::fromBinary($facility->id);
        InventoryData::stock($this->bar->id, $this->beer->id, '1');

        $sales->consume($facilityId, [new ConsumptionLine($this->beer->id, '1', Ids::uuid7())], 'order', Ids::uuid7());
        $this->assertSame('0.0000', InventoryData::onHand($this->bar->id, $this->beer->id));

        try {
            $sales->consume($facilityId, [new ConsumptionLine($this->beer->id, '1', Ids::uuid7())], 'order', Ids::uuid7());
            $this->fail('expected insufficient_stock');
        } catch (ApiProblem $e) {
            $this->assertSame('insufficient_stock', $e->problemCode);
            $this->assertSame('0.0000', $e->extensions['meta']['availableQuantity']);
        }

        DB::table('stock_location')->where('id', Ids::toBinary($this->bar->id))->update(['allow_negative' => 1]);
        $sales->consume($facilityId, [new ConsumptionLine($this->beer->id, '2', Ids::uuid7())], 'order', Ids::uuid7());
        $this->assertSame('-2.0000', InventoryData::onHand($this->bar->id, $this->beer->id), 'negative stock is visible, not silently blocked');
        $this->assertSame('-2.0000', InventoryData::ledgerSum($this->bar->id, $this->beer->id));
    }

    public function test_stock_movement_is_append_only_at_the_database_level(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '3');

        foreach (['UPDATE stock_movement SET note = "tamper"', 'UPDATE stock_movement SET qty_delta = 99', 'DELETE FROM stock_movement'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail("statement should have been rejected: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('stock_movement')->count());
    }

    public function test_consumption_is_idempotent_per_order_line_and_reversal_restores_exactly_once(): void
    {
        $sales = app(InventoryConsumption::class);
        $facilityId = Ids::fromBinary(DB::table('facility_unit')->first()->id);
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        $order = Ids::uuid7();
        $line = Ids::uuid7();

        $a = $sales->consume($facilityId, [new ConsumptionLine($this->beer->id, '3', $line)], 'order', $order);
        $b = $sales->consume($facilityId, [new ConsumptionLine($this->beer->id, '3', $line)], 'order', $order); // replay (OrderSent twice)
        $this->assertSame(1, $a->appliedCount());
        $this->assertSame(1, $b->replayedCount());
        $this->assertSame('7.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StockConsumed')->count(), 'replay emits no second event');

        $r1 = $sales->reverse('order', $order);
        $r2 = $sales->reverse('order', $order);
        $this->assertSame(1, $r1->appliedCount());
        $this->assertSame(0, $r2->appliedCount());
        $this->assertSame('10.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame('SALE_RETURN', DB::table('stock_movement')->where('reason', 'SALE_RETURN')->value('reason'));
    }

    public function test_line_level_reversal_only_restores_that_line(): void
    {
        $sales = app(InventoryConsumption::class);
        $facilityId = Ids::fromBinary(DB::table('facility_unit')->first()->id);
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        InventoryData::stock($this->bar->id, $this->wine->id, '10');
        $order = Ids::uuid7();
        [$l1, $l2] = [Ids::uuid7(), Ids::uuid7()];
        $sales->consume($facilityId, [new ConsumptionLine($this->beer->id, '2', $l1), new ConsumptionLine($this->wine->id, '5', $l2)], 'order', $order);

        $sales->reverse('order', $order, [$l2]);

        $this->assertSame('8.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame('10.0000', InventoryData::onHand($this->bar->id, $this->wine->id));
    }

    public function test_consume_requires_a_transaction_and_an_existing_store(): void
    {
        $facility = TestData::facility($this->t, 'nostore');
        try {
            app(InventoryConsumption::class)->consume($facility->id, [new ConsumptionLine($this->beer->id, '1', Ids::uuid7())], 'order', Ids::uuid7());
            $this->fail('expected stock_location_not_configured');
        } catch (ApiProblem $e) {
            $this->assertSame('stock_location_not_configured', $e->problemCode);
        }
    }

    public function test_sub_facility_sells_from_its_parents_store(): void
    {
        $restaurant = TestData::facility($this->t, 'restaurant');
        $counter = TestData::facility($this->t, 'counter', $restaurant->id);
        $store = InventoryData::location($this->t, 'Restaurant Store', 'FACILITY_STORE', $restaurant->id);
        InventoryData::stock($store->id, $this->beer->id, '5');

        app(InventoryConsumption::class)->consume($counter->id, [new ConsumptionLine($this->beer->id, '2', Ids::uuid7())], 'order', Ids::uuid7());

        $this->assertSame('3.0000', InventoryData::onHand($store->id, $this->beer->id));
    }

    public function test_shortages_is_an_advisory_precheck(): void
    {
        $sales = app(InventoryConsumption::class);
        $facilityId = Ids::fromBinary(DB::table('facility_unit')->first()->id);
        InventoryData::stock($this->bar->id, $this->beer->id, '2');

        $this->assertSame([], $sales->shortages($facilityId, [new ConsumptionLine($this->beer->id, '2', Ids::uuid7())]));
        $s = $sales->shortages($facilityId, [new ConsumptionLine($this->beer->id, '3', Ids::uuid7())]);
        $this->assertSame($this->beer->id, $s[0]['itemId']);
        $this->assertSame('2.0000', $s[0]['available']);
    }
}
