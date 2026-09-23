<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Models\Booking;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Booking <-> other-node plumbing surface for the SYNC module (architecture/sync/event-catalogue.md):
 *  - snapshot():            the payload shape emitted in BookingConfirmedLocally / OnlineBookingCreated / Cloud hold replies;
 *  - applySnapshot():       Cloud->Local `OnlineBookingCreated` (or a delegated Cloud hold) -> create the local mirror rows;
 *  - applyCancelled():      `OnlineBookingCancelled`  -> release the slots locally;
 *  - applyRescheduled():    `BookingRescheduled`      -> move the local allocation.
 * All idempotent by booking id. A unit collision (the Cloud pool and the Local reserve are disjoint, so this means a
 * manual override or bad data) is a 409 `slot_unavailable` that the Sync inbox must record as a conflict, not swallow.
 * Call inside the inbox transaction (they do not open their own).
 */
final class BookingSyncApplier
{
    private const FMT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly SlotGrid $grid) {}

    /** @return array<string, mixed> */
    public function snapshot(Booking $b, ?string $entitlementId = null): array
    {
        $item = DB::table('booking_item')->where('booking_id', Ids::toBinary($b->id))->first();

        return [
            'bookingId' => $b->id, 'number' => $b->number, 'organizationId' => $b->organization_id, 'siteId' => $b->site_id, 'facilityId' => $b->facility_unit_id,
            'resourceId' => $b->resource_id, 'itemId' => $item ? Ids::fromBinary($item->id) : null, 'status' => $b->status, 'source' => $b->source,
            'originNode' => $b->origin_node, 'pool' => $b->allocation_pool, 'start' => $b->start_at->format('Y-m-d\TH:i:s\Z'), 'end' => $b->end_at->format('Y-m-d\TH:i:s\Z'),
            'quantity' => $b->quantity, 'wholeResource' => $b->whole_resource, 'unitNos' => SlotAllocations::units($b->id),
            'customer' => ['name' => $b->customer_name, 'phone' => $b->customer_phone, 'email' => $b->customer_email, 'membershipId' => $b->membership_id],
            'currency' => $b->currency, 'total' => $b->total, 'amountPaid' => $b->amount_paid,
            'holdExpiresAt' => $b->hold_expires_at?->format('Y-m-d\TH:i:s\Z'), 'orderId' => $b->order_id, 'entitlementId' => $entitlementId ?? $b->entitlement_id,
            'rowVersion' => $b->row_version,
        ];
    }

    /** @param array<string, mixed> $s */
    public function applySnapshot(array $s): Booking
    {
        if ($existing = Booking::query()->find($s['bookingId'])) {
            return $existing; // idempotent redelivery
        }
        $resource = BookableResource::query()->findOrFail($s['resourceId']);
        $start = CarbonImmutable::parse($s['start'], 'UTC');
        $end = CarbonImmutable::parse($s['end'], 'UTC');
        $starts = $this->grid->slotStartsFor($resource, $start, $end) ?? throw ApiProblem::unprocessable('validation_failed', 'Snapshot slot is not on this resource\'s grid.');
        $c = $s['customer'] ?? [];
        $item = $s['itemId'] ?? Ids::uuid7();
        $b = fn ($v) => $v === null ? null : Ids::toBinary($v);

        DB::table('booking')->insert([
            'id' => $b($s['bookingId']), 'organization_id' => $b($s['organizationId']), 'site_id' => $b($s['siteId']), 'facility_unit_id' => $b($s['facilityId']), 'resource_id' => $b($s['resourceId']),
            'number' => $s['number'], 'status' => $s['status'], 'source' => $s['source'] ?? 'STAFF', 'origin_node' => $s['originNode'] ?? 'CLOUD', 'allocation_pool' => $s['pool'] ?? 'CLOUD',
            'start_at' => $start->format(self::FMT), 'end_at' => $end->format(self::FMT), 'quantity' => $s['quantity'], 'whole_resource' => ! empty($s['wholeResource']) ? 1 : 0,
            'customer_name' => $c['name'] ?? null, 'customer_phone' => $c['phone'] ?? null, 'customer_email' => $c['email'] ?? null, 'membership_id' => $b($c['membershipId'] ?? null),
            'currency' => $s['currency'] ?? 'NGN', 'total' => $s['total'], 'amount_paid' => $s['amountPaid'] ?? '0.0000',
            'hold_expires_at' => ! empty($s['holdExpiresAt']) ? CarbonImmutable::parse($s['holdExpiresAt'], 'UTC')->format(self::FMT) : null,
            'order_id' => $b($s['orderId'] ?? null), 'entitlement_id' => $b($s['entitlementId'] ?? null), 'row_version' => $s['rowVersion'] ?? 1,
        ]);
        DB::table('booking_item')->insert([
            'id' => $b($item), 'booking_id' => $b($s['bookingId']), 'resource_id' => $b($s['resourceId']), 'slot_start' => $start->format(self::FMT), 'slot_end' => $end->format(self::FMT),
            'qty' => $s['quantity'], 'whole_resource' => ! empty($s['wholeResource']) ? 1 : 0, 'unit_price' => '0.0000', 'line_total' => $s['total'],
        ]);
        $this->insertAllocations($resource, $item, $starts, $end, $s['unitNos'] ?? [1], $s['pool'] ?? 'CLOUD', $s['status'], $s['holdExpiresAt'] ?? null);

        return Booking::query()->findOrFail($s['bookingId']);
    }

    public function applyCancelled(string $bookingId, ?string $reason = null): void
    {
        $n = DB::table('booking')->where('id', Ids::toBinary($bookingId))->whereNotIn('status', ['CANCELLED', 'EXPIRED', 'COMPLETED'])->update([
            'status' => 'CANCELLED', 'cancel_reason' => $reason, 'cancelled_at' => CarbonImmutable::now('UTC')->format(self::FMT), 'hold_expires_at' => null, 'row_version' => DB::raw('row_version + 1'),
        ]);
        if ($n > 0) {
            SlotAllocations::release($bookingId);
            DB::table('entitlement')->where('booking_id', Ids::toBinary($bookingId))->where('status', 'ACTIVE')->update(['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now('UTC')->format(self::FMT)]);
        }
    }

    public function applyRescheduled(string $bookingId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $b = Booking::query()->with('resource')->findOrFail($bookingId);
        $units = SlotAllocations::units($bookingId);
        $starts = $this->grid->slotStartsFor($b->resource, $start, $end) ?? throw ApiProblem::unprocessable('validation_failed', 'Slot is not on this resource\'s grid.');
        $item = DB::table('booking_item')->where('booking_id', Ids::toBinary($bookingId))->first();
        SlotAllocations::release($bookingId);
        $this->insertAllocations($b->resource, Ids::fromBinary($item->id), $starts, $end, $units, $b->allocation_pool, $b->status, $b->hold_expires_at?->format('Y-m-d\TH:i:s\Z'));
        DB::table('booking_item')->where('id', $item->id)->update(['slot_start' => $start->format(self::FMT), 'slot_end' => $end->format(self::FMT)]);
        DB::table('booking')->where('id', Ids::toBinary($bookingId))->update(['start_at' => $start->format(self::FMT), 'end_at' => $end->format(self::FMT), 'reschedule_count' => DB::raw('reschedule_count + 1'), 'row_version' => DB::raw('row_version + 1')]);
    }

    /** @param list<CarbonImmutable> $starts @param list<int> $units */
    private function insertAllocations(BookableResource $resource, string $itemId, array $starts, CarbonImmutable $rangeEnd, array $units, string $pool, string $status, ?string $holdExpiresAt): void
    {
        $rows = [];
        foreach ($units as $unit) {
            foreach ($starts as $i => $st) {
                $rows[] = [
                    'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($resource->organization_id), 'site_id' => Ids::toBinary($resource->site_id),
                    'resource_id' => Ids::toBinary($resource->id), 'unit_no' => $unit, 'slot_start' => $st->format(self::FMT), 'slot_end' => ($starts[$i + 1] ?? $rangeEnd)->format(self::FMT),
                    'booking_item_id' => Ids::toBinary($itemId), 'pool' => $pool, 'status' => in_array($status, ['CONFIRMED', 'RESCHEDULED', 'COMPLETED'], true) ? 'CONFIRMED' : 'HELD',
                    'hold_expires_at' => $holdExpiresAt ? CarbonImmutable::parse($holdExpiresAt, 'UTC')->format(self::FMT) : null,
                ];
            }
        }
        try {
            DB::table('slot_allocation')->insert($rows);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('slot_unavailable', 'A different booking already holds those units (allocation conflict from the other node).', ['meta' => ['reason' => 'allocation_conflict']]);
            }
            throw $e;
        }
    }
}
