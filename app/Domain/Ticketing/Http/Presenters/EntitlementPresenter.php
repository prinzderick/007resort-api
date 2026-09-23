<?php

namespace App\Domain\Ticketing\Http\Presenters;

use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Models\EntitlementItem;
use Carbon\CarbonImmutable;

final class EntitlementPresenter
{
    /** @return array<string, mixed> contract `Entitlement` */
    public static function entitlement(Entitlement $e): array
    {
        $e->loadMissing('items');

        return [
            'id' => $e->id, 'qrToken' => $e->qr_token, 'status' => self::status($e), 'orderId' => $e->order_id, 'bookingId' => $e->booking_id,
            'holderName' => $e->holder_name, 'items' => $e->items->map(fn ($i) => self::item($i))->values()->all(),
            'issuedAt' => $e->issued_at->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** ACTIVE entitlements whose every windowed item has lapsed are reported EXPIRED (derived, not stored). */
    public static function status(Entitlement $e): string
    {
        if ($e->status !== 'ACTIVE') {
            return $e->status;
        }
        $windowed = $e->items->filter(fn ($i) => $i->kind === EntitlementItem::ACCESS && $i->valid_until !== null);
        $now = CarbonImmutable::now('UTC');

        return $windowed->isNotEmpty() && $windowed->every(fn ($i) => $i->valid_until < $now && $i->qty_redeemed < $i->qty) && $e->items->every(fn ($i) => $i->kind === EntitlementItem::ACCESS)
            ? 'EXPIRED' : 'ACTIVE';
    }

    /** @return array<string, mixed> contract `EntitlementItem` (+ additive quantityReturned/quantityInside/subKind) */
    public static function item(EntitlementItem $i): array
    {
        return [
            'id' => $i->id,
            'kind' => in_array($i->kind, ['ACCESS', 'RENTAL'], true) ? $i->kind : 'ITEM',
            'subKind' => $i->kind,
            'name' => $i->name,
            'facilityId' => $i->facility_unit_id,
            'quantity' => (int) round($i->qty),
            'quantityRedeemed' => (int) round($i->qty_redeemed),
            'quantityReturned' => (int) round($i->qty_returned),
            'quantityInside' => (int) round($i->qty_inside),
            'validationMode' => $i->validation_mode,
            'validFrom' => $i->valid_from?->format('Y-m-d\TH:i:s\Z'),
            'validUntil' => $i->valid_until?->format('Y-m-d\TH:i:s\Z'),
            'rentalStatus' => $i->rentalStatus(),
        ];
    }
}
