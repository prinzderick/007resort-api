<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\CloudBookingAuthority;
use App\Domain\Booking\Contracts\ConnectivityProbe;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Support\AllocationPlan;
use App\Domain\Booking\Support\HoldCommand;
use App\Support\Http\ApiProblem;
use App\Support\Node;

/**
 * Booking Authority policy engine (architecture/sync/booking-authority-and-offline-allocation.md, ADR-0013).
 *
 * ONE decision point per booking attempt, chosen per RESOURCE (`bookable_resource.offline_strategy`):
 *
 *   Single-node MVP (config booking.cloud_enabled=false): this node is the sole authority, all units, pool CLOUD.
 *
 *   Cloud node (authority while reachable):
 *     - ONLINE channel: only the Cloud pool = units 1..(capacity - local_reserve_units), so the Local offline reserve is
 *       never handed to the website; strategy C additionally refuses when the Local heartbeat is older than
 *       `online_stale_after_seconds` (or the resource is not `online_bookable`) — "temporarily disable online availability".
 *     - STAFF channel (admin / delegated from Local): the full unit range.
 *
 *   Local node:
 *     - Cloud reachable and an authority is bound -> DELEGATE (Local -> Cloud synchronous call, not a sync event).
 *     - Offline: A = commit only from the Local reserve (units above the Cloud pool); B = refuse `offline_not_allowed`
 *       ("requires connectivity to book", THIS resource only); C = behaves like A locally (its effect is on the website).
 *
 * Disjoint unit ranges make A conflict-free by construction: the same UNIQUE(resource, unit, slot) guard covers both pools.
 * The Cloud->Local plumbing itself belongs to the Sync module; it only has to implement ConnectivityProbe and
 * CloudBookingAuthority.
 */
final class BookingAuthorityPolicy
{
    public function __construct(
        private readonly ConnectivityProbe $probe,
        private readonly CloudBookingAuthority $cloud,
    ) {}

    public function plan(BookableResource $resource, HoldCommand $cmd, bool $forceOffline = false): AllocationPlan
    {
        $units = $resource->unitCount();
        $reserve = min($resource->local_reserve_units, $units);
        $cloudHi = $units - $reserve;

        if (! config('booking.cloud_enabled')) {
            return new AllocationPlan('CLOUD', 1, $units, false, 'single-node authority');
        }

        if (Node::isCloud()) {
            if ($cmd->channel === 'ONLINE') {
                if (! $resource->online_bookable) {
                    throw ApiProblem::conflict('capability_disabled', 'This resource is not bookable online.');
                }
                if ($resource->offline_strategy === BookableResource::STRATEGY_C) {
                    $age = $this->probe->localHeartbeatAgeSeconds();
                    if ($age === null || $age > $resource->online_stale_after_seconds) {
                        throw ApiProblem::conflict('capability_disabled', 'Online booking for this resource is temporarily unavailable.', ['meta' => ['strategy' => 'C', 'heartbeatAgeSeconds' => $age]]);
                    }
                }
                if ($cloudHi < 1 || $cmd->wholeResource && $reserve > 0) {
                    throw ApiProblem::conflict('slot_unavailable', 'Online capacity for this resource is not available.');
                }

                return new AllocationPlan('CLOUD', 1, $cloudHi, false, 'cloud pool');
            }

            return new AllocationPlan('CLOUD', 1, $units, false, 'cloud authority (staff)');
        }

        // ---- Local node with a Cloud deployed ----
        if (! $forceOffline && $this->cloud->isConfigured() && $this->probe->cloudReachable()) {
            return new AllocationPlan('CLOUD', 1, $units, true, 'delegate to cloud authority');
        }

        return match ($resource->offline_strategy) {
            BookableResource::STRATEGY_B => throw ApiProblem::conflict('offline_not_allowed', 'This resource requires connectivity to book.', ['meta' => ['strategy' => 'B']]),
            default => $this->localReserve($resource, $cmd, $units, $reserve),
        };
    }

    private function localReserve(BookableResource $resource, HoldCommand $cmd, int $units, int $reserve): AllocationPlan
    {
        if ($reserve < 1) {
            throw ApiProblem::conflict('offline_not_allowed', 'No offline capacity is reserved for this resource; connectivity is required.', ['meta' => ['strategy' => 'A', 'localReserveUnits' => 0]]);
        }
        if ($cmd->wholeResource && $reserve < $units) {
            throw ApiProblem::conflict('offline_not_allowed', 'Whole-resource booking is not possible from the offline reserve.', ['meta' => ['strategy' => 'A']]);
        }

        return new AllocationPlan('LOCAL', $units - $reserve + 1, $units, false, 'local offline reserve');
    }
}
