<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Inventory\Contracts\RentalGateway;
use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Sports Store release / return -> Inventory's pooled-quantity rental ledger (RENTAL_OUT / RENTAL_IN).
 *
 * A rental entitlement item carries its Catalog `product_id`; Catalog's `product_stock_link` says which stock item(s) it consumes
 * (x quantity_per_unit). The stock moves at the facility's own store location (`stock_location.facility_unit_id`, default-sale first)
 * of the item's facility (the Sports Store). Products without links, or facilities without a location, are simply not stock-tracked
 * (no-op). Runs INSIDE the release/return transaction: `insufficient_stock` (409) rolls the release back. Inventory is idempotent per
 * (reference, id), so a retried release/return never double-moves stock.
 * A DAMAGED / LOST return does NOT put the units back on the shelf (they stay out until an adjustment/write-off is recorded).
 * Tagged assets (`rental_asset`) are not addressable from an entitlement item and are out of scope here.
 * Bound only when Inventory's `RentalGateway` exists (TicketingServiceProvider).
 */
final class InventoryRentalStockHook implements RentalStockHook
{
    public function __construct(private readonly RentalGateway $inventory) {}

    public function rentalOut(array $context): void
    {
        foreach ($this->movements($context) as [$location, $item, $qty]) {
            $this->inventory->issueQuantity($location, $item, $qty, 'entitlement_item', $context['entitlementItemId'], null, $context['staffId']);
        }
    }

    public function rentalIn(array $context): void
    {
        if (($context['condition'] ?? 'OK') !== 'OK') {
            return;
        }
        foreach ($this->movements($context) as [$location, $item, $qty]) {
            $this->inventory->returnQuantity($location, $item, $qty, 'entitlement_item', $context['entitlementItemId'], null, $context['staffId']);
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> [locationId, itemId, quantity] */
    private function movements(array $c): array
    {
        if (empty($c['productId']) || empty($c['facilityUnitId'])) {
            return [];
        }
        $location = DB::table('stock_location')->where('facility_unit_id', Ids::toBinary($c['facilityUnitId']))->where('is_active', 1)
            ->orderByDesc('is_sale_default')->orderBy('id')->value('id');
        if ($location === null) {
            return [];
        }
        $out = [];
        foreach (DB::table('product_stock_link')->where('product_id', Ids::toBinary($c['productId']))->orderBy('id')->get(['stock_item_id', 'quantity_per_unit']) as $link) {
            $out[] = [Ids::fromBinary($location), Ids::fromBinary($link->stock_item_id), bcmul((string) $c['quantity'], (string) $link->quantity_per_unit, 4)];
        }

        return $out;
    }
}
