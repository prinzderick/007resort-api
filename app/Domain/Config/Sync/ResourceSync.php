<?php

namespace App\Domain\Config\Sync;

use App\Domain\Booking\Models\BookableResource;
use App\Support\Sync\Outbox;

/** Sync snapshot of a bookable resource (created/edited through the Booking admin endpoints); the peer applies it via the `bookableResource` target. */
final class ResourceSync
{
    /** @return array<string, mixed> */
    public static function snapshot(BookableResource $r): array
    {
        return [
            'organizationId' => $r->organization_id, 'siteId' => $r->site_id, 'facilityId' => $r->facility_unit_id, 'code' => $r->code, 'name' => $r->name, 'mode' => $r->mode, 'capacity' => (int) $r->capacity,
            'slotMinutes' => (int) $r->slot_minutes, 'maxSlotsPerBooking' => (int) $r->max_slots_per_booking, 'price' => (string) $r->price, 'wholePrice' => $r->whole_price === null ? null : (string) $r->whole_price,
            'productId' => $r->product_id, 'allowWholeResource' => (bool) $r->allow_whole_resource, 'onlineBookable' => (bool) $r->online_bookable, 'active' => (bool) $r->is_active,
            'offlineStrategy' => $r->offline_strategy, 'localReserveUnits' => (int) $r->local_reserve_units, 'onlineStaleAfterSeconds' => (int) $r->online_stale_after_seconds,
        ];
    }

    /** Call inside the change's transaction. */
    public static function emit(BookableResource $r): void
    {
        Outbox::record('ConfigurationUpdated', 'BookableResource', $r->id, ['domain' => 'bookableResource', 'changes' => self::snapshot($r)], (int) $r->row_version,
            organizationId: $r->organization_id, siteId: $r->site_id, facilityId: $r->facility_unit_id);
    }
}
