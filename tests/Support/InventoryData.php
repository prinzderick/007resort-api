<?php

namespace Tests\Support;

use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Support\MovementSpec;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Inventory fixtures against the real schema. */
final class InventoryData
{
    public static function item(array $t, string $sku, string $name = 'Test item', string $unit = 'EACH', string $reorder = '0'): InventoryItem
    {
        return InventoryItem::create(['organization_id' => $t['org'], 'sku' => $sku, 'name' => $name, 'unit' => $unit, 'reorder_level' => $reorder]);
    }

    public static function location(array $t, string $name, string $kind = 'FACILITY_STORE', ?string $facilityId = null, bool $allowNegative = false, bool $saleDefault = true): StockLocation
    {
        return StockLocation::create([
            'organization_id' => $t['org'], 'site_id' => $t['site'], 'facility_unit_id' => $facilityId, 'name' => $name, 'kind' => $kind,
            'allow_negative' => $allowNegative, 'is_sale_default' => $saleDefault,
        ]);
    }

    /** Put opening stock on the ledger the legitimate way (a RECEIPT leg). */
    public static function stock(string $locationId, string $itemId, string $qty): void
    {
        DB::transaction(fn () => app(StockLedger::class)->post(new MovementSpec($itemId, $locationId, $qty, 'RECEIPT', 'seed', Ids::uuid7())));
    }

    public static function onHand(string $locationId, string $itemId): string
    {
        return app(StockLedger::class)->onHand($itemId, $locationId);
    }

    public static function ledgerSum(string $locationId, string $itemId): string
    {
        return (string) DB::selectOne('SELECT COALESCE(SUM(qty_delta),0) s FROM stock_movement WHERE item_id = ? AND location_id = ?', [
            TestData::bin($itemId), TestData::bin($locationId),
        ])->s;
    }
}
