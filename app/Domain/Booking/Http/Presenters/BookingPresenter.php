<?php

namespace App\Domain\Booking\Http\Presenters;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Models\Booking;
use App\Support\Money\Money;

final class BookingPresenter
{
    /** @return array<string, mixed> contract `Booking` (+ additive fields) */
    public static function booking(Booking $b): array
    {
        $b->loadMissing('resource');

        return [
            'id' => $b->id, 'number' => $b->number, 'resourceId' => $b->resource_id, 'resourceName' => $b->resource?->name, 'facilityId' => $b->facility_unit_id,
            'start' => self::ts($b->start_at), 'end' => self::ts($b->end_at), 'quantity' => $b->quantity, 'status' => $b->status,
            'holdExpiresAt' => $b->hold_expires_at ? self::ts($b->hold_expires_at) : null,
            'customer' => ['name' => $b->customer_name, 'phone' => $b->customer_phone, 'email' => $b->customer_email, 'membershipId' => $b->membership_id],
            'total' => Money::of($b->total)->amount, 'amountPaid' => Money::of($b->amount_paid)->amount, 'orderId' => $b->order_id, 'entitlementId' => $b->entitlement_id,
            'source' => $b->source, 'rowVersion' => $b->row_version, 'createdAt' => $b->created_at->format('Y-m-d\TH:i:s.v\Z'),
            'wholeResource' => $b->whole_resource, 'cancellationFee' => Money::of($b->cancellation_fee)->amount,
        ];
    }

    /** @return array<string, mixed> contract `BookableResource` (+ authority config for managers) */
    public static function resource(BookableResource $r): array
    {
        return [
            'id' => $r->id, 'facilityId' => $r->facility_unit_id, 'name' => $r->name, 'mode' => $r->mode, 'capacity' => $r->capacity, 'slotMinutes' => $r->slot_minutes,
            'productId' => $r->product_id, 'price' => Money::of($r->price)->amount, 'active' => $r->is_active,
            'code' => $r->code, 'allowWholeResource' => $r->allow_whole_resource, 'maxSlotsPerBooking' => $r->max_slots_per_booking, 'onlineBookable' => $r->online_bookable,
            'wholePrice' => $r->whole_price === null ? null : Money::of($r->whole_price)->amount,
            'authority' => ['offlineStrategy' => $r->offline_strategy, 'localReserveUnits' => $r->local_reserve_units, 'onlineStaleAfterSeconds' => $r->online_stale_after_seconds],
            'rowVersion' => $r->row_version,
        ];
    }

    public static function ts($t): string
    {
        return $t->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
    }
}
