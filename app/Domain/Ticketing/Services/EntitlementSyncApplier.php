<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Models\Entitlement;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Entitlement <-> other-node plumbing for the Sync module. An entitlement issued on one node (Cloud: online booking; Local: Reception)
 * must exist on the other so the property can validate it at the gate (QR token identical, same signing key: docs/BOOKING_TICKETING.md).
 *  - snapshot():  the `EntitlementIssued` payload (everything needed to recreate it, incl. the QR token);
 *  - apply():     idempotent mirror by entitlement id (existing => untouched).
 *  - mirrorRedemption(): reporting mirror of TicketRedeemed / RentalReleased / RentalReturned (exactly once per redemption id).
 * Call inside the inbox transaction.
 */
final class EntitlementSyncApplier
{
    private const FMT = 'Y-m-d H:i:s.u';

    /** @return array<string, mixed> */
    public function snapshot(Entitlement $e): array
    {
        $e->loadMissing('items');

        return [
            'entitlementId' => $e->id, 'organizationId' => $e->organization_id, 'siteId' => $e->site_id, 'qrToken' => $e->qr_token, 'sourceKey' => $e->source_key,
            'status' => $e->status, 'bookingId' => $e->booking_id, 'orderId' => $e->order_id, 'customerId' => $e->customer_id, 'holderName' => $e->holder_name,
            'issuedAt' => $e->issued_at->format('Y-m-d\TH:i:s.u\Z'),
            'items' => $e->items->map(fn ($i) => [
                'id' => $i->id, 'kind' => $i->kind, 'name' => $i->name, 'facilityUnitId' => $i->facility_unit_id, 'ticketTypeId' => $i->ticket_type_id, 'productId' => $i->product_id,
                'orderLineId' => $i->order_line_id, 'validationMode' => $i->validation_mode, 'qty' => number_format($i->qty, 3, '.', ''), 'qtyRedeemed' => number_format($i->qty_redeemed, 3, '.', ''),
                'validFrom' => $i->valid_from?->format('Y-m-d\TH:i:s.u\Z'), 'validUntil' => $i->valid_until?->format('Y-m-d\TH:i:s.u\Z'), 'sortOrder' => (int) $i->sort_order,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $s @return bool true when created, false when it already existed */
    public function apply(array $s): bool
    {
        $bin = Ids::toBinary($s['entitlementId']);
        if (DB::table('entitlement')->where('id', $bin)->exists() || DB::table('entitlement')->where('qr_token', $s['qrToken'])->exists()) {
            return false;
        }
        $b = fn ($v) => $v === null ? null : Ids::toBinary($v);
        $t = fn ($v) => $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format(self::FMT);
        DB::table('entitlement')->insert([
            'id' => $bin, 'organization_id' => $b($s['organizationId']), 'site_id' => $b($s['siteId']), 'qr_token' => $s['qrToken'], 'source_key' => $s['sourceKey'],
            'status' => $s['status'], 'booking_id' => $b($s['bookingId'] ?? null), 'order_id' => $b($s['orderId'] ?? null), 'customer_id' => $b($s['customerId'] ?? null),
            'holder_name' => $s['holderName'] ?? null, 'issued_at' => $t($s['issuedAt']),
        ]);
        foreach ($s['items'] as $i) {
            DB::table('entitlement_item')->insert([
                'id' => Ids::toBinary($i['id']), 'entitlement_id' => $bin, 'kind' => $i['kind'], 'name' => $i['name'], 'facility_unit_id' => $b($i['facilityUnitId'] ?? null),
                'ticket_type_id' => $b($i['ticketTypeId'] ?? null), 'product_id' => $b($i['productId'] ?? null), 'order_line_id' => $b($i['orderLineId'] ?? null),
                'validation_mode' => $i['validationMode'], 'qty' => $i['qty'], 'qty_redeemed' => $i['qtyRedeemed'] ?? 0, 'valid_from' => $t($i['validFrom'] ?? null),
                'valid_until' => $t($i['validUntil'] ?? null), 'sort_order' => $i['sortOrder'] ?? 0,
            ]);
        }

        return true;
    }

    public function cancel(string $entitlementId): void
    {
        DB::table('entitlement')->where('id', Ids::toBinary($entitlementId))->where('status', 'ACTIVE')->update(['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now('UTC')->format(self::FMT)]);
    }

    /**
     * Mirror one redemption made on the other node (reporting/audit; this node never redeems for it). The redemption row id is the
     * exactly-once key: quantities move only when the row was newly inserted. @param array<string, mixed> $p event payload
     *
     * @return bool|null true = mirrored, false = already mirrored, null = the item is not here yet (defer)
     */
    public function mirrorRedemption(string $action, array $p): ?bool
    {
        $item = DB::table('entitlement_item')->where('id', Ids::toBinary($p['entitlementItemId']))->first();
        if ($item === null) {
            return null;
        }
        $inserted = DB::table('redemption')->insertOrIgnore([
            'id' => Ids::toBinary($p['redemptionId']), 'entitlement_item_id' => $item->id, 'action' => $action, 'qty' => $p['qty'],
            'device_id' => empty($p['deviceId']) ? null : Ids::toBinary($p['deviceId']), 'staff_id' => empty($p['staffId']) ? null : Ids::toBinary($p['staffId']),
            'facility_unit_id' => empty($p['facilityId']) ? null : Ids::toBinary($p['facilityId']),
            'item_condition' => $p['condition'] ?? null, 'amount' => $p['damageCharge'] ?? null, 'created_at' => CarbonImmutable::parse($p['at'], 'UTC')->format(self::FMT),
        ]);
        if ($inserted === 0) {
            return false;
        }
        $q = (string) $p['qty'];
        match ($action) {
            'ENTRY' => DB::update('UPDATE entitlement_item SET qty_redeemed = LEAST(qty, qty_redeemed + ?), qty_inside = LEAST(qty_redeemed, qty_inside + IF(validation_mode = ?, ?, 0)) WHERE id = ?', [$q, 'ENTRY_EXIT', $q, $item->id]),
            'EXIT' => DB::update('UPDATE entitlement_item SET qty_inside = GREATEST(0, qty_inside - ?) WHERE id = ?', [$q, $item->id]),
            'RELEASE' => DB::update('UPDATE entitlement_item SET qty_redeemed = qty WHERE id = ?', [$item->id]),
            'RETURN' => DB::update('UPDATE entitlement_item SET qty_returned = qty WHERE id = ? AND qty_redeemed = qty', [$item->id]),
        };

        return true;
    }
}
