<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Support\ConsumptionLine;
use App\Domain\Inventory\Support\Qty;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Consumes / restores stock for ORDER lines through Catalog's `product_stock_link` (product -> stock item x quantity_per_unit).
 * Products without links (services, untracked goods) consume nothing. Idempotent per order line (see InventoryConsumption).
 */
class ProductStockLinks
{
    public function __construct(private readonly InventoryConsumption $consumption) {}

    /**
     * @param  list<array{lineId: string, productId: string, qty: int|string}>  $orderLines
     * @return int number of item deductions attempted (0 when nothing is stock-linked)
     *
     * @throws ApiProblem insufficient_stock (409) — meta carries itemId, and productId/lineId of the failing order line
     */
    public function consumeOrderLines(string $facilityUnitId, string $orderId, array $orderLines, ?string $actor = null): int
    {
        $links = $this->linksFor(array_column($orderLines, 'productId'));
        $lines = [];
        $origin = [];
        foreach ($orderLines as $l) {
            foreach ($links[$l['productId']] ?? [] as $link) {
                $lines[] = new ConsumptionLine($link['itemId'], Qty::mul((string) $l['qty'], $link['perUnit']), $l['lineId']);
                $origin[$l['lineId'].'|'.$link['itemId']] = $l['productId'];
            }
        }
        if ($lines === []) {
            return 0;
        }
        try {
            $this->consumption->consume($facilityUnitId, $lines, 'order', $orderId, $actor);
        } catch (ApiProblem $e) {
            if ($e->problemCode === 'insufficient_stock') {
                // tell the POS which order line / product ran out
                $meta = $e->extensions['meta'] ?? [];
                foreach ($lines as $cl) {
                    if ($cl->itemId === ($meta['itemId'] ?? null)) {
                        $meta += ['lineId' => $cl->lineRef, 'productId' => $origin[$cl->lineRef.'|'.$cl->itemId] ?? null];
                        break;
                    }
                }
                throw new ApiProblem($e->status, $e->problemCode, $e->getMessage(), $e->title, ['meta' => $meta] + $e->extensions, $e->headers);
            }
            throw $e;
        }

        return count($lines);
    }

    /** Void: restore whatever the given order lines consumed (no-op for lines that consumed nothing). */
    public function restoreOrderLines(string $orderId, array $lineIds, ?string $actor = null): void
    {
        if ($lineIds !== []) {
            $this->consumption->reverse('order', $orderId, $lineIds, $actor);
        }
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, list<array{itemId: string, perUnit: string}>>
     */
    public function linksFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('product_stock_link')->whereIn('product_id', array_map(Ids::toBinary(...), array_unique($productIds)))->orderBy('id')->get();
        foreach ($rows as $r) {
            $out[Ids::fromBinary($r->product_id)][] = ['itemId' => Ids::fromBinary($r->stock_item_id), 'perUnit' => Qty::normalize($r->quantity_per_unit)];
        }

        return $out;
    }
}
