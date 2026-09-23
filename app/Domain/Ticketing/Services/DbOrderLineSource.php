<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads a paid order for entitlement issuance. Read-only lookups over Orders/Catalog tables.
 *  - TICKET lines -> ACCESS entitlements (ticket type by product; its facility is where the ticket is valid);
 *  - RENTAL lines -> RENTAL items, released at the Sports Store (facility code config('ticketing.store_facility_code'));
 *  - GOOD lines that are sold at the Sports Store (product_facility) are flagged `storeAvailable` so a Reception order can
 *    hand them over at the store against the same QR (Reception flow: architecture/10 §4).
 */
final class DbOrderLineSource implements OrderLineSource
{
    public function forOrder(string $orderId): ?array
    {
        if (! Schema::hasTable('order_line') || ! Schema::hasTable('product')) {
            throw ApiProblem::unprocessable('validation_failed', 'Issuing by orderId requires the Orders module.', ['orderId' => ['orders module not installed']]);
        }
        $order = DB::table('order')->where('id', Ids::toBinary($orderId))->first();
        if ($order === null) {
            return null;
        }
        $store = DB::table('facility_unit')->where('site_id', $order->site_id)->where('code', config('ticketing.store_facility_code'))->whereNull('deleted_at')->value('id');
        $rows = DB::table('order_line as l')->join('product as p', 'p.id', '=', 'l.product_id')
            ->where('l.order_id', $order->id)->whereNotIn('l.status', ['VOIDED', 'REMOVED'])
            ->orderBy('l.line_no')->get(['l.id', 'l.product_id', 'l.product_name', 'l.quantity', 'p.kind']);
        $atStore = $store === null ? [] : DB::table('product_facility')->where('facility_unit_id', $store)->where('is_available', 1)
            ->whereIn('product_id', $rows->pluck('product_id')->all())->pluck('product_id')->map(fn ($b) => Ids::fromBinary($b))->all();
        $storeId = $store === null ? null : Ids::fromBinary($store);

        $lines = $rows->map(fn ($r) => [
            'lineId' => Ids::fromBinary($r->id), 'productId' => Ids::fromBinary($r->product_id), 'name' => $r->product_name, 'kind' => $r->kind === 'GOOD' ? 'GOOD' : $r->kind,
            'quantity' => (int) $r->quantity, 'facilityId' => in_array($r->kind, ['RENTAL', 'GOOD'], true) ? $storeId : null,
            'storeAvailable' => $r->kind === 'GOOD' && in_array(Ids::fromBinary($r->product_id), $atStore, true),
        ])->all();

        return [
            'orderId' => Ids::fromBinary($order->id), 'organizationId' => Ids::fromBinary($order->organization_id), 'siteId' => Ids::fromBinary($order->site_id),
            'holderName' => $order->customer_name, 'facilityId' => Ids::fromBinary($order->facility_unit_id), 'status' => $order->status,
            'total' => Money::of($order->total)->amount, 'amountPaid' => Money::of($order->amount_paid)->amount,
            'fullyPaid' => Money::of($order->amount_paid)->compare(Money::of($order->total)) >= 0 && ! in_array($order->status, ['VOIDED', 'PENDING_APPROVAL'], true),
            'lines' => $lines,
        ];
    }
}
