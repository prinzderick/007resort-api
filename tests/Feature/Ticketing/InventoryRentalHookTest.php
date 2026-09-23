<?php

namespace Tests\Feature\Ticketing;

use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Domain\Ticketing\Services\InventoryRentalStockHook;
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

        $log = new \ArrayObject;
        $calls = \Mockery::mock($gatewayInterface);
        $calls->shouldReceive('issueQuantity')->andReturnUsing(function (string $l, string $i, string $q, string $rt, string $rid) use ($log) {
            $log[] = ['out', $l, $i, $q, $rt, $rid];

            return [];
        });
        $calls->shouldReceive('returnQuantity')->andReturnUsing(function (string $l, string $i, string $q, string $rt, string $rid) use ($log) {
            $log[] = ['in', $l, $i, $q, $rt, $rid];

            return [];
        });
        $this->app->instance($gatewayInterface, $calls);
        $this->assertInstanceOf(InventoryRentalStockHook::class, $this->app->make(RentalStockHook::class));

        [, $tok] = $this->staffWith($w, 'store', self::SCAN_PERMS);
        $ent = app(EntitlementService::class)->issue('inv:'.Ids::uuid7(), $w['t']['org'], $w['t']['site'], [
            ['kind' => 'RENTAL', 'name' => 'Racket x2', 'qty' => 2, 'facilityUnitId' => $w['store']->id, 'productId' => $product],
            ['kind' => 'RENTAL', 'name' => 'Untracked', 'qty' => 1, 'facilityUnitId' => $w['store']->id],
        ], holderName: 'X');
        [$racket, $untracked] = $ent->items->all();

        $this->postJson("/api/v1/entitlements/{$ent->id}/release", ['itemIds' => [$racket->id, $untracked->id]], $this->idem($tok))->assertOk();
        $this->assertSame([['out', $loc, $item, '2.0000', 'entitlement_item', $racket->id]], $log->getArrayCopy());
        $this->postJson("/api/v1/entitlements/{$ent->id}/return", ['itemIds' => [$racket->id], 'condition' => 'DAMAGED'], $this->idem($tok))->assertOk();
        $this->assertCount(1, $log); // damaged units do not go back on the shelf
        $this->postJson("/api/v1/entitlements/{$ent->id}/return", ['itemIds' => [$untracked->id], 'condition' => 'OK'], $this->idem($tok))->assertOk();
        $this->assertCount(1, $log); // no product -> not stock tracked
    }
}
