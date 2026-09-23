<?php

namespace App\Domain\Booking\Sync;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingSyncApplier;
use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sync inbox applier for the booking events of the OTHER node (registered by BookingServiceProvider when the Sync module exists):
 *   OnlineBookingCreated (Cloud) / BookingConfirmedLocally (Local)  -> mirror the booking + its slot allocations;
 *   OnlineBookingCancelled / BookingCancelled                       -> release the slots, cancel the entitlement;
 *   BookingRescheduled                                              -> move the allocation.
 * The Cloud pool and the Local reserve are disjoint unit ranges, so a unit collision means an override or bad data: it is recorded as a
 * BOOKING conflict for a human (architecture/sync/booking-authority-and-offline-allocation.md §3.A), never silently resolved.
 * An event for a booking that has not arrived yet is DEFERRED (retried when its predecessor lands).
 */
final class BookingEventApplier implements SyncApplier
{
    public const EVENT_TYPES = ['OnlineBookingCreated', 'BookingConfirmedLocally', 'OnlineBookingCancelled', 'BookingCancelled', 'BookingRescheduled'];

    public function __construct(private readonly BookingSyncApplier $bookings) {}

    public function apply(InboundEvent $e): ApplyResult
    {
        $p = $e->payload;
        try {
            return match ($e->eventType) {
                'OnlineBookingCreated', 'BookingConfirmedLocally' => $this->created($e, $p),
                'OnlineBookingCancelled', 'BookingCancelled' => $this->cancelled($e, $p),
                'BookingRescheduled' => $this->rescheduled($e, $p),
                default => throw new \InvalidArgumentException("Unsupported booking event {$e->eventType}"),
            };
        } catch (ApiProblem $problem) {
            if ($problem->problemCode === 'slot_unavailable') {
                return ApplyResult::conflict('BOOKING', null, $e->entityVersion, ['overlapping' => $this->overlapping($p)], $problem->getMessage());
            }
            throw $problem;
        }
    }

    /** @param array<string, mixed> $p */
    private function created(InboundEvent $e, array $p): ApplyResult
    {
        if (Booking::query()->whereKey($p['bookingId'])->exists()) {
            return ApplyResult::applied('booking already present');
        }
        $this->bookings->applySnapshot($p);

        return ApplyResult::applied();
    }

    /** @param array<string, mixed> $p */
    private function cancelled(InboundEvent $e, array $p): ApplyResult
    {
        if (! Booking::query()->whereKey($p['bookingId'])->exists()) {
            return ApplyResult::deferred('booking has not arrived yet');
        }
        $this->bookings->applyCancelled($p['bookingId'], $p['reason'] ?? null);

        return ApplyResult::applied();
    }

    /** @param array<string, mixed> $p */
    private function rescheduled(InboundEvent $e, array $p): ApplyResult
    {
        if (! Booking::query()->whereKey($p['bookingId'])->exists()) {
            return ApplyResult::deferred('booking has not arrived yet');
        }
        $this->bookings->applyRescheduled($p['bookingId'], CarbonImmutable::parse($p['new']['start'], 'UTC'), CarbonImmutable::parse($p['new']['end'], 'UTC'));

        return ApplyResult::applied();
    }

    /** What already occupies the slots of an incoming booking (for the conflict reviewer). @param array<string, mixed> $p @return list<array<string, mixed>> */
    private function overlapping(array $p): array
    {
        $start = $p['start'] ?? $p['new']['start'] ?? null;
        $end = $p['end'] ?? $p['new']['end'] ?? null;
        $resource = $p['resourceId'] ?? null;
        if ($start === null || $end === null || $resource === null) {
            return [];
        }

        return DB::table('booking')->where('resource_id', Ids::toBinary($resource))->whereNotIn('status', ['CANCELLED', 'EXPIRED'])
            ->where('start_at', '<', CarbonImmutable::parse($end, 'UTC')->format('Y-m-d H:i:s.u'))->where('end_at', '>', CarbonImmutable::parse($start, 'UTC')->format('Y-m-d H:i:s.u'))
            ->limit(10)->get(['id', 'number', 'status', 'allocation_pool', 'row_version'])
            ->map(fn ($r) => ['id' => Ids::fromBinary($r->id), 'number' => $r->number, 'status' => $r->status, 'pool' => $r->allocation_pool, 'rowVersion' => (int) $r->row_version])->all();
    }
}
