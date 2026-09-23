<?php

namespace Tests\Feature\Ticketing;

use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingHelpers;
use Tests\Support\OrdersFixture;
use Tests\TestCase;

/** Runs only when Inventory's RentalGateway exists (integrated build): release/return move pooled stock, once. */
class InventoryRentalHookTest extends TestCase
{
    use BookingHelpers;

    public function test_release_and_return_move_pooled_rental_stock_through_inventory(): void
    {
        $gatewayInterface = 'App\Domain\Inventory\Contracts\RentalGateway';
        if (! interface_exists($gatewayInterface)) {
            $this->markTestSkipped('Inventory module is not installed on this branch.');
        }
        $w = $this->world();
        $org = Ids::toBinary($w['t']['org']);
        $cat = OrdersFixture::insert('product_category', ['organization_id' => $org, 'name' => 'Sports']);
        $product = OrdersFixture::insert('product', ['organization_id' => $org, 'category_id' => Ids::toBinary($cat), 'sku' => 'RACKET', 'name' => 'Racket', 'kind' => 'RENTAL']);
        $item = OrdersFixture::insert('inventory_item', ['organization_id' => $org, 'sku' => 'RACKET', 'name' => 'Racket']);
        DB::table('product_stock_link')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'product_id' => Ids::toBinary($product), 'stock_item_id' => Ids::toBinary($item), 'quantity_per_unit' => '1.0000']);
        $loc = OrdersFixture::insert('stock_location', ['organization_id' => $org, 'site_id' => Ids::toBinary($w['t']['site']), 'facility_unit_id' => Ids::toBinary($w['store']->id), 'name' => 'Sports Store', 'kind' => 'FACILITY_STORE', 'allow_negative' => 0, 'is_sale_default' => 1]);

        $calls = new class implements $gatewayInterface
        {
            public array $log = [];

            public function issueAsset(string $assetIdOrTag, string $referenceType, string $referenceId, ?string $actorStaffId = null): array
            {
                return [];
            }

            public function returnAsset(string $assetIdOrTag, ?string $conditionNote = null, bool $damaged = false, ?string $actorStaffId = null): array
            {
                return [];
            }

            public function issueQuantity(string $locationId, string $itemId, string $quantity, string $referenceType, string $referenceId, ?string $lineId = null, ?string $actorStaffId = null): array
            {
                $this->log[] = ['out', $locationId, $itemId, $quantity, $referenceType, $referenceId];

                return [];
            }

            public function returnQuantity(string $locationId, string $itemId, string $quantity, string $referenceType, string $referenceId, ?string $lineId = null, ?string $actorStaffId = null): array
            {
                $this->log[] = ['in', $locationId, $itemId, $quantity, $referenceType, $referenceId];

                return [];
            }
        };
        $this->app->instance($gatewayInterface, $calls);
        $this->assertInstanceOf(\App\Domain\Ticketing\Services\InventoryRentalStockHook::class, $this->app->make(RentalStockHook::class));

        [, $tok] = $this->staffWith($w, 'store', self::SCAN_PERMS);
        $ent = app(EntitlementService::class)->issue('inv:'.Ids::uuid7(), $w['t']['org'], $w['t']['site'], [
            ['kind' => 'RENTAL', 'name' => 'Racket x2', 'qty' => 2, 'facilityUnitId' => $w['store']->id, 'productId' => $product],
            ['kind' => 'RENTAL', 'name' => 'Untracked', 'qty' => 1, 'facilityUnitId' => $w['store']->id],
        ], holderName: 'X');
        [$racket, $untracked] = $ent->items->all();

        $this->postJson("/api/v1/entitlements/{$ent->id}/release", ['itemIds' => [$racket->id, $untracked->id]], $this->idem($tok))->assertOk();
        $this->assertSame([['out', $loc, $item, '2.0000', 'entitlement_item', $racket->id]], $calls->log);
        $this->postJson("/api/v1/entitlements/{$ent->id}/return", ['itemIds' => [$racket->id], 'condition' => 'DAMAGED'], $this->idem($tok))->assertOk();
        $this->assertCount(1, $calls->log); // damaged units do not go back on the shelf
        $this->postJson("/api/v1/entitlements/{$ent->id}/return", ['itemIds' => [$untracked->id], 'condition' => 'OK'], $this->idem($tok))->assertOk();
        $this->assertCount(1, $calls->log); // no product -> not stock tracked
    }
}
