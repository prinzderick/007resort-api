<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Contracts\RentalGateway;
use App\Domain\Inventory\Database\InventoryDemoSeeder;
use App\Domain\Inventory\Services\ConsumptionService;
use App\Domain\Inventory\Services\StockDocuments;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryData;
use Tests\Support\TestData;
use Tests\TestCase;

/** Reconciliation command + schedule, demo seeder, rental hooks. */
class InventoryOpsTest extends TestCase
{
    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
    }

    public function test_reconcile_passes_on_a_healthy_ledger_and_detects_and_repairs_drift(): void
    {
        $main = InventoryData::location($this->t, 'Main Store', 'MAIN_STORE', null, false, false);
        $item = InventoryData::item($this->t, 'X');
        $bar = InventoryData::location($this->t, 'Bar', 'BAR', TestData::facility($this->t, 'bar')->id);
        app(StockDocuments::class)->receive(['locationId' => $main->id, 'lines' => [['itemId' => $item->id, 'quantity' => '10']]]);
        app(StockDocuments::class)->transfer(['fromLocationId' => $main->id, 'toLocationId' => $bar->id, 'lines' => [['itemId' => $item->id, 'quantity' => '4']]]);

        $this->assertSame(0, Artisan::call('r007:inventory:reconcile'));

        // simulate corruption of the projection (someone edits stock_balance by hand)
        DB::table('stock_balance')->where('location_id', Ids::toBinary($main->id))->update(['qty_on_hand' => '99']);
        $this->assertSame(1, Artisan::call('r007:inventory:reconcile', ['--json' => true]));
        $out = json_decode(Artisan::output(), true);
        $this->assertSame('99.0000', $out['drift'][0]['balance']);
        $this->assertSame('6.0000', $out['drift'][0]['ledger']);
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'inventory.reconcile_drift')->count());

        Artisan::call('r007:inventory:reconcile', ['--fix' => true, '--json' => true]);
        $this->assertSame('6.0000', InventoryData::onHand($main->id, $item->id), 'the ledger is the source of truth');
        $this->assertSame(0, Artisan::call('r007:inventory:reconcile'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inventory.reconcile.repair')->count());
    }

    public function test_reconcile_detects_movements_without_a_balance_row(): void
    {
        $loc = InventoryData::location($this->t, 'Main Store', 'MAIN_STORE', null, false, false);
        $item = InventoryData::item($this->t, 'X');
        InventoryData::stock($loc->id, $item->id, '3');
        DB::table('stock_balance')->delete();

        $this->assertSame(1, Artisan::call('r007:inventory:reconcile'));
        Artisan::call('r007:inventory:reconcile', ['--fix' => true]);
        $this->assertSame('3.0000', InventoryData::onHand($loc->id, $item->id));
    }

    public function test_reconcile_is_scheduled_nightly_in_lagos_time(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'r007:inventory:reconcile'));
        $this->assertCount(1, $events);
        $this->assertSame('30 2 * * *', $events->first()->expression); // 02:30 in the event's own timezone
        $this->assertSame('Africa/Lagos', (string) $events->first()->timezone);
    }

    public function test_demo_seeder_builds_stores_items_and_real_stock_and_is_idempotent(): void
    {
        $r = (new InventoryDemoSeeder)->run();
        $this->assertGreaterThanOrEqual(60, $r['items']);
        $this->assertTrue($r['openingStockPosted']);
        $names = DB::table('stock_location')->pluck('name')->all();
        foreach (['Main Store', 'Restaurant Store', 'Bush Bar Store', 'Salon Store', 'Supermarket Shelf', 'Sports Store'] as $n) {
            $this->assertContains($n, $names);
        }
        $movements = DB::table('stock_movement')->count();
        $this->assertGreaterThan(200, $movements);
        $this->assertGreaterThan(0, DB::table('rental_asset')->count());
        $this->assertSame(0, Artisan::call('r007:inventory:reconcile'));
        $this->assertSame(0, DB::table('stock_balance as b')->join('stock_location as l', 'l.id', '=', 'b.location_id')->where('l.allow_negative', 0)->where('b.qty_on_hand', '<', 0)->count());

        $again = (new InventoryDemoSeeder)->run();
        $this->assertFalse($again['openingStockPosted']);
        $this->assertSame($movements, DB::table('stock_movement')->count());
        $this->assertSame($r['items'], DB::table('inventory_item')->count());
    }

    public function test_demo_seed_command_output_and_hook_event(): void
    {
        $this->assertSame(0, Artisan::call('r007:inventory:demo-seed'));
        $this->assertStringContainsString('items', Artisan::output());
        $this->assertGreaterThanOrEqual(60, DB::table('inventory_item')->count());
    }

    // ---- rentals -----------------------------------------------------------------------------------------------------

    private function asset(string $tag = 'RKT-01'): array
    {
        $store = InventoryData::location($this->t, 'Sports Store', 'FACILITY_STORE', TestData::facility($this->t, 'sports')->id);
        $item = InventoryData::item($this->t, 'RKT', 'Racket');
        $id = Ids::uuid7();
        DB::table('rental_asset')->insert(['id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->t['org']), 'item_id' => Ids::toBinary($item->id), 'location_id' => Ids::toBinary($store->id), 'asset_tag' => $tag]);

        return [$store, $item, $id];
    }

    public function test_tagged_asset_issue_return_lifecycle_and_replays(): void
    {
        [, , $id] = $this->asset();
        $rentals = app(RentalGateway::class);
        $ref = Ids::uuid7();

        $issued = $rentals->issueAsset('RKT-01', 'entitlement_item', $ref);
        $this->assertSame('ISSUED', $issued['status']);
        $this->assertFalse($issued['replayed']);
        $this->assertTrue($rentals->issueAsset($id, 'entitlement_item', $ref)['replayed'], 'same reference re-issuing is a replay');
        try {
            $rentals->issueAsset('RKT-01', 'entitlement_item', Ids::uuid7());
            $this->fail('second customer must be refused');
        } catch (ApiProblem $e) {
            $this->assertSame('rental_asset_unavailable', $e->problemCode);
            $this->assertSame(409, $e->status);
        }

        $back = $rentals->returnAsset('RKT-01', 'scuffed grip', true);
        $this->assertSame('MAINTENANCE', $back['status'], 'damaged returns go to maintenance');
        $this->assertTrue($rentals->returnAsset('RKT-01')['replayed']);
        $this->assertNotNull($back['returnedAt']);

        DB::table('rental_asset')->update(['status' => 'AVAILABLE']);
        $this->assertSame('ISSUED', $rentals->issueAsset('RKT-01', 'entitlement_item', Ids::uuid7())['status']);
        $this->assertSame('AVAILABLE', $rentals->returnAsset('RKT-01')['status']);
        try {
            $rentals->issueAsset('NOPE', 'x', Ids::uuid7());
            $this->fail();
        } catch (ApiProblem $e) {
            $this->assertSame('rental_asset_not_found', $e->problemCode);
        }
    }

    public function test_pooled_rental_quantities_move_through_the_guarded_ledger_idempotently(): void
    {
        [$store, $item] = $this->asset();
        InventoryData::stock($store->id, $item->id, '2');
        $rentals = app(RentalGateway::class);
        $ent = Ids::uuid7();
        $line = Ids::uuid7();

        $rentals->issueQuantity($store->id, $item->id, '2', 'entitlement_item', $ent, $line);
        $this->assertTrue($rentals->issueQuantity($store->id, $item->id, '2', 'entitlement_item', $ent, $line)['replayed']);
        $this->assertSame('0.0000', InventoryData::onHand($store->id, $item->id));
        try {
            $rentals->issueQuantity($store->id, $item->id, '1', 'entitlement_item', Ids::uuid7(), Ids::uuid7());
            $this->fail('nothing left to rent out');
        } catch (ApiProblem $e) {
            $this->assertSame('insufficient_stock', $e->problemCode);
        }
        $rentals->returnQuantity($store->id, $item->id, '2', 'entitlement_item', $ent, $line);
        $rentals->returnQuantity($store->id, $item->id, '2', 'entitlement_item', $ent, $line);
        $this->assertSame('2.0000', InventoryData::onHand($store->id, $item->id));
        $this->assertSame(['RENTAL_OUT', 'RENTAL_IN'], DB::table('stock_movement')->whereIn('reason', ['RENTAL_OUT', 'RENTAL_IN'])->orderBy('created_at')->orderBy('id')->pluck('reason')->all());
    }

    public function test_consumption_timing_rule_reads_the_facility_operating_rule(): void
    {
        $f = TestData::facility($this->t, 'restaurant');
        $svc = app(ConsumptionService::class);
        $this->assertSame('SEND', $svc->timingFor($f->id), 'default');

        $capId = Ids::uuid7();
        DB::table('facility_capability')->insert(['id' => Ids::toBinary($capId), 'facility_unit_id' => Ids::toBinary($f->id), 'capability_code' => 'INVENTORY', 'is_enabled' => 1]);
        DB::table('operating_rule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'facility_capability_id' => Ids::toBinary($capId), 'rule_key' => 'stock_consumption_timing', 'rule_value' => 'settle']);
        $this->assertSame('SETTLE', $svc->timingFor($f->id));
    }
}
