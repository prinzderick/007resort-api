<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\BookingPaymentGateway;
use App\Domain\Booking\Contracts\CloudBookingAuthority;
use App\Domain\Booking\Contracts\CloudUnreachableException;
use App\Domain\Booking\Events\BookingHoldExpired;
use App\Domain\Booking\Models\Blackout;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\Booking\Support\AllocationPlan;
use App\Domain\Booking\Support\HoldCommand;
use App\Domain\Booking\Support\Tx;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Node;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking lifecycle (architecture/10): hold -> confirm (payment + entitlement) -> cancel / reschedule, plus hold expiry.
 *
 * The atomic check-and-reserve is a multi-row INSERT into `slot_allocation` guarded by UNIQUE (resource_id, unit_no,
 * slot_start): of two concurrent holds for the last slot MySQL rejects exactly one, which becomes 409 `slot_unavailable`.
 * Expired-but-unswept holds are cleared lazily, by primary key, only when they are what blocks a request.
 * Every mutation writes audit + outbox in the same transaction. Callers may wrap these in their own transaction
 * (the `idempotent` middleware does); every method opens one (savepoint) when needed.
 */
final class BookingService
{
    private const FMT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly BookingAuthorityPolicy $policy,
        private readonly SlotGrid $grid,
        private readonly BookingRules $rules,
        private readonly BookingNumbers $numbers,
        private readonly EntitlementService $entitlements,
        private readonly BookingPaymentGateway $payments,
        private readonly CloudBookingAuthority $cloud,
        private readonly BookingSyncApplier $sync,
    ) {}

    // ------------------------------------------------------------------------------------------------ HOLD

    public function hold(HoldCommand $cmd): Booking
    {
        $resource = BookableResource::query()->where('id', $cmd->resourceId)->where('is_active', 1)->whereNull('deleted_at')->first();
        if ($resource === null || (($org = Tenant::organizationId()) !== null && $resource->organization_id !== $org)) {
            throw ApiProblem::notFound('not_found', 'Bookable resource not found.');
        }
        [$qty, $whole] = $this->normaliseQuantity($resource, $cmd);
        $rules = $this->rules->for($resource);
        $starts = $this->grid->slotStartsFor($resource, $cmd->start, $cmd->end);
        if ($starts === null) {
            throw ApiProblem::unprocessable('validation_failed', 'The requested time is not a run of this resource\'s slots.', ['start' => ['must align to the resource slot grid']]);
        }
        if (count($starts) > $resource->max_slots_per_booking) {
            throw ApiProblem::unprocessable('validation_failed', "At most {$resource->max_slots_per_booking} slots per booking.", ['end' => ['too many slots']]);
        }
        $now = CarbonImmutable::now('UTC');
        if ($cmd->start < $now->addMinutes((int) $rules['min_notice_minutes']) || $cmd->start > $now->addDays((int) $rules['max_advance_days'])) {
            throw ApiProblem::conflict('slot_unavailable', 'That time is outside the bookable window.', ['meta' => ['reason' => 'booking_window']]);
        }
        $this->assertNotBlackedOut($resource, $cmd->start, $cmd->end);

        $plan = $this->policy->plan($resource, $cmd);
        if ($plan->delegateToCloud) {
            try {
                return $this->sync->applySnapshot($this->cloud->hold($cmd));
            } catch (CloudUnreachableException) {
                $plan = $this->policy->plan($resource, $cmd, forceOffline: true);
            }
        }

        return Tx::run(fn () => $this->place($resource, $cmd, $plan, $starts, $qty, $whole, (int) $rules['hold_ttl_seconds']));
    }

    /** @param list<CarbonImmutable> $starts */
    private function place(BookableResource $resource, HoldCommand $cmd, AllocationPlan $plan, array $starts, int $qty, bool $whole, int $ttl): Booking
    {
        $this->lockResource($resource->id); // BEFORE any insert that FK-references the resource (avoids S->X upgrade deadlocks)
        $now = CarbonImmutable::now('UTC');
        $bookingId = $cmd->bookingId ?? Ids::uuid7();
        $itemId = Ids::uuid7();
        $expires = $now->addSeconds($ttl);
        [$unitPrice, $lineTotal] = $this->price($resource, count($starts), $qty, $whole);
        $cust = $cmd->customer ?? [];

        DB::table('booking')->insert([
            'id' => Ids::toBinary($bookingId), 'organization_id' => Ids::toBinary($resource->organization_id), 'site_id' => Ids::toBinary($resource->site_id),
            'facility_unit_id' => Ids::toBinary($resource->facility_unit_id), 'resource_id' => Ids::toBinary($resource->id),
            'number' => 'TMP-'.$bookingId, 'status' => 'HELD', 'source' => $cmd->channel === 'ONLINE' ? 'ONLINE' : 'STAFF',
            'origin_node' => Node::isCloud() ? 'CLOUD' : 'LOCAL', 'allocation_pool' => $plan->pool,
            'start_at' => $cmd->start->format(self::FMT), 'end_at' => $cmd->end->format(self::FMT), 'quantity' => $qty, 'whole_resource' => $whole ? 1 : 0,
            'customer_name' => $cust['name'] ?? null, 'customer_phone' => $cust['phone'] ?? null, 'customer_email' => $cust['email'] ?? null,
            'membership_id' => isset($cust['membershipId']) ? Ids::toBinary($cust['membershipId']) : null,
            'currency' => $resource->currency, 'total' => $lineTotal, 'amount_paid' => '0.0000', 'hold_expires_at' => $expires->format(self::FMT),
            'created_by' => ($s = $cmd->staffId ?? RequestContext::staffId()) ? Ids::toBinary($s) : null,
            'device_id' => ($d = $cmd->deviceId ?? RequestContext::deviceId()) ? Ids::toBinary($d) : null,
        ]);
        DB::table('booking_item')->insert([
            'id' => Ids::toBinary($itemId), 'booking_id' => Ids::toBinary($bookingId), 'resource_id' => Ids::toBinary($resource->id),
            'slot_start' => $cmd->start->format(self::FMT), 'slot_end' => $cmd->end->format(self::FMT), 'qty' => $qty, 'whole_resource' => $whole ? 1 : 0,
            'unit_price' => $unitPrice, 'line_total' => $lineTotal,
        ]);

        $this->allocate($resource, $plan, $itemId, $starts, $cmd->end, $qty, $whole, $expires);

        DB::table('booking')->where('id', Ids::toBinary($bookingId))->update(['number' => $this->numbers->next()]);

        return Booking::query()->findOrFail($bookingId);
    }

    /**
     * The atomic reserve. Returns the unit numbers claimed; throws 409 slot_unavailable when the capacity is gone.
     *
     * No "read free units, then write": each unit is CLAIMED by a single multi-row INSERT (all of its slots or none)
     * and MySQL's UNIQUE (resource_id, unit_no, slot_start) decides the race — a duplicate-key error just means "that
     * unit is taken, try the next". This is correct under any isolation level (a snapshot read can be stale; the index
     * cannot). If fewer units than required were claimed the savepoint rolls the partial claim back.
     *
     * @param  list<CarbonImmutable>  $starts
     * @return list<int>
     */
    private function allocate(BookableResource $resource, AllocationPlan $plan, string $itemId, array $starts, CarbonImmutable $rangeEnd, int $qty, bool $whole, ?CarbonImmutable $holdExpires): array
    {
        $slotEnds = [];
        foreach ($starts as $i => $s) {
            $slotEnds[$s->format(self::FMT)] = ($starts[$i + 1] ?? $rangeEnd);
        }
        $slotKeys = array_keys($slotEnds);
        $resourceBin = Ids::toBinary($resource->id);
        $range = range($plan->unitLo, $plan->unitHi);
        $unavailable = fn (string $why) => ApiProblem::conflict('slot_unavailable', 'That slot is no longer available.', ['meta' => ['reason' => $why]]);

        $this->clearExpiredBlockers($resourceBin, $slotKeys); // ONE pass: stale unpaid holds must not block real customers

        return (function () use ($resource, $plan, $itemId, $slotKeys, $slotEnds, $resourceBin, $range, $qty, $whole, $holdExpires, $unavailable) {
            $need = $whole ? count($range) : $qty;
            $chosen = [];
            foreach ($range as $unit) {
                if (count($chosen) >= $need) {
                    break;
                }
                $rows = [];
                foreach ($slotKeys as $key) {
                    $rows[] = [
                        'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($resource->organization_id), 'site_id' => Ids::toBinary($resource->site_id),
                        'resource_id' => $resourceBin, 'unit_no' => $unit, 'slot_start' => $key, 'slot_end' => $slotEnds[$key]->format(self::FMT),
                        'booking_item_id' => Ids::toBinary($itemId), 'pool' => $plan->pool, 'status' => 'HELD', 'hold_expires_at' => $holdExpires?->format(self::FMT),
                    ];
                }
                try {
                    DB::table('slot_allocation')->insert($rows); // ONE statement: every slot of this unit, or none
                    $chosen[] = $unit;
                } catch (QueryException $e) {
                    $code = $e->errorInfo[1] ?? null;
                    if ($code === 1062) {
                        if ($whole) {
                            throw $unavailable('whole_resource_in_use');
                        }

                        continue; // this unit is taken (by a committed or racing booking): try the next
                    }
                    if (in_array($code, [1213, 1205], true)) {
                        throw $unavailable('contention'); // deadlock victim / lock wait timeout: safe for the client to retry
                    }
                    throw $e;
                }
            }
            if (count($chosen) < $need) {
                throw $unavailable('capacity_exhausted');
            }

            return $chosen;
        })();
    }

    /** Expire the (already past-TTL) unpaid holds that occupy any of these slots, once. @param list<string> $slotKeys */
    private function clearExpiredBlockers(string $resourceBin, array $slotKeys): void
    {
        $now = CarbonImmutable::now('UTC')->format(self::FMT);
        $bookingIds = DB::table('slot_allocation as sa')->join('booking_item as bi', 'bi.id', '=', 'sa.booking_item_id')
            ->where('sa.resource_id', $resourceBin)->whereIn('sa.slot_start', $slotKeys)
            ->where('sa.status', 'HELD')->whereNotNull('sa.hold_expires_at')->where('sa.hold_expires_at', '<', $now)
            ->distinct()->pluck('bi.booking_id');
        foreach ($bookingIds as $bid) {
            $this->expireBooking(Ids::fromBinary($bid));
        }
    }

    // --------------------------------------------------------------------------------------------- CONFIRM

    /**
     * Confirm a HELD booking: capture payment (via the bound gateway), mark CONFIRMED, pin the allocations and issue the
     * entitlement — ONE transaction. `$extraItems` = RENTAL/GOODS items bought with the slot (Reception flow).
     *
     * @param  list<array<string, mixed>>  $tenders
     * @param  list<array<string, mixed>>  $extraItems
     */
    public function confirm(string $bookingId, ?int $expectedRowVersion, array $tenders = [], ?string $cashSessionId = null, ?string $paystackReference = null, array $extraItems = []): Booking
    {
        return Tx::run(function () use ($bookingId, $expectedRowVersion, $tenders, $cashSessionId, $paystackReference, $extraItems) {
            $b = $this->lock($bookingId, $expectedRowVersion);
            $this->assertConfirmable($b);
            $paid = $this->payments->capture($b->id, $b->total, $tenders, $cashSessionId, $paystackReference);

            // With the Payments module the capture itself fires PaymentCaptured, whose listener has ALREADY confirmed this booking
            // (same transaction). Then there is nothing left to do but return it; otherwise (unlinked gateway) confirm it here.
            $current = Booking::query()->with('resource')->findOrFail($b->id);
            if ($current->status === Booking::CONFIRMED) {
                return $current;
            }

            return $this->finalizeConfirmation($current, $paid['amountPaid'], $paid['orderId'] ?? null, $extraItems);
        });
    }

    /**
     * Confirm a booking whose payment was already captured elsewhere (Orders/Payments `PaymentCaptured` / `OrderSettled`
     * listener, Paystack webhook). Idempotent: an already-CONFIRMED booking is returned unchanged.
     *
     * @param  list<array<string, mixed>>  $extraItems
     */
    public function confirmPaid(string $bookingId, string $amountPaid, ?string $orderId, array $extraItems = []): Booking
    {
        return Tx::run(function () use ($bookingId, $amountPaid, $orderId, $extraItems) {
            $b = $this->lock($bookingId, null);
            if ($b->status === Booking::CONFIRMED) {
                return $b;
            }
            $this->assertConfirmable($b);
            if (Money::of($amountPaid)->compare(Money::of($b->total)) < 0) {
                throw new ApiProblem(422, 'amount_mismatch', 'Paid amount is below the booking total.', 'Amount mismatch', ['meta' => ['expected' => $b->total, 'received' => Money::of($amountPaid)->amount]]);
            }

            return $this->finalizeConfirmation($b, Money::of($amountPaid)->amount, $orderId, $extraItems);
        });
    }

    private function assertConfirmable(Booking $b): void
    {
        if ($b->status === Booking::EXPIRED || ($b->status === Booking::HELD && ! $b->isHoldLive())) {
            throw ApiProblem::conflict('hold_expired', 'The hold expired before it was confirmed; place a new hold.');
        }
        if (! in_array($b->status, [Booking::HELD, Booking::PENDING_PAYMENT], true)) {
            throw ApiProblem::conflict('booking_state_invalid', "A {$b->status} booking cannot be confirmed.");
        }
        if ($b->status === Booking::PENDING_PAYMENT && $b->hold_expires_at !== null && ! $b->isHoldLive()) {
            throw ApiProblem::conflict('hold_expired', 'The hold expired before it was confirmed; place a new hold.');
        }
    }

    /** @param list<array<string, mixed>> $extraItems */
    private function finalizeConfirmation(Booking $b, string $amountPaid, ?string $orderId, array $extraItems): Booking
    {
        $bin = Ids::toBinary($b->id);
        $now = CarbonImmutable::now('UTC')->format(self::FMT);
        DB::table('booking')->where('id', $bin)->update([
            'status' => 'CONFIRMED', 'amount_paid' => $amountPaid, 'order_id' => $orderId ? Ids::toBinary($orderId) : $b->getRawOriginal('order_id'),
            'hold_expires_at' => null, 'confirmed_at' => $now, 'row_version' => DB::raw('row_version + 1'),
        ]);
        $itemIds = DB::table('booking_item')->where('booking_id', $bin)->pluck('id')->all();
        DB::table('slot_allocation')->whereIn('booking_item_id', $itemIds)->update(['status' => 'CONFIRMED', 'hold_expires_at' => null]);

        $fresh = Booking::query()->with('resource')->findOrFail($b->id);
        $ent = $this->entitlements->issueForBooking($fresh, $extraItems);
        DB::table('booking')->where('id', $bin)->update(['entitlement_id' => Ids::toBinary($ent->id)]);
        $fresh = Booking::query()->with('resource')->findOrFail($b->id);

        Audit::record('booking.confirm', 'Booking', $b->id, ['status' => $b->status], ['status' => 'CONFIRMED', 'amountPaid' => $fresh->amount_paid, 'entitlementId' => $ent->id, 'orderId' => $orderId],
            organizationId: $b->organization_id, siteId: $b->site_id, facilityUnitId: $b->facility_unit_id);
        Outbox::record(Node::isCloud() ? 'OnlineBookingCreated' : 'BookingConfirmedLocally', 'Booking', $b->id, $this->syncPayload($fresh, $ent->id),
            entityVersion: $fresh->row_version, organizationId: $b->organization_id, siteId: $b->site_id, facilityId: $b->facility_unit_id);

        return $fresh;
    }

    // ---------------------------------------------------------------------------------------------- CANCEL

    public function cancel(string $bookingId, ?int $expectedRowVersion, string $reason): Booking
    {
        return Tx::run(function () use ($bookingId, $expectedRowVersion, $reason) {
            $b = $this->lock($bookingId, $expectedRowVersion);
            if (! in_array($b->status, [Booking::HELD, Booking::PENDING_PAYMENT, Booking::CONFIRMED, Booking::RESCHEDULED], true)) {
                throw ApiProblem::conflict('booking_state_invalid', "A {$b->status} booking cannot be cancelled.");
            }
            $now = CarbonImmutable::now('UTC');
            $fee = '0.0000';
            if ($b->status === Booking::CONFIRMED || $b->status === Booking::RESCHEDULED) {
                if ($b->start_at <= $now) {
                    throw ApiProblem::conflict('booking_state_invalid', 'The booking has already started; it can no longer be cancelled.');
                }
                $rules = $this->rules->for($b->resource);
                if ($b->start_at < $now->addMinutes((int) $rules['cancel_cutoff_minutes'])) {
                    $fee = Money::of($b->amount_paid)->percent($rules['cancel_fee_percent'])->amount; // inside the free-cancellation cutoff
                }
            }
            $this->entitlements->cancelForBooking($b->id);
            $this->releaseAllocations($b->id);
            DB::table('booking')->where('id', Ids::toBinary($b->id))->update([
                'status' => 'CANCELLED', 'cancelled_at' => $now->format(self::FMT), 'cancel_reason' => mb_substr($reason, 0, 255), 'cancellation_fee' => $fee,
                'hold_expires_at' => null, 'row_version' => DB::raw('row_version + 1'),
            ]);
            $fresh = Booking::query()->with('resource')->findOrFail($b->id);
            $refundDue = Money::of($b->amount_paid)->sub(Money::of($fee))->amount;

            Audit::record('booking.cancel', 'Booking', $b->id, ['status' => $b->status], ['status' => 'CANCELLED', 'reason' => $reason, 'cancellationFee' => $fee, 'refundDue' => $refundDue],
                organizationId: $b->organization_id, siteId: $b->site_id, facilityUnitId: $b->facility_unit_id);
            Outbox::record(Node::isCloud() ? 'OnlineBookingCancelled' : 'BookingCancelled', 'Booking', $b->id,
                ['bookingId' => $b->id, 'number' => $b->number, 'reason' => $reason, 'cancellationFee' => $fee, 'refundDue' => $refundDue, 'orderId' => $b->order_id],
                entityVersion: $fresh->row_version, organizationId: $b->organization_id, siteId: $b->site_id, facilityId: $b->facility_unit_id);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------------------------------- RESCHEDULE

    /** New slot is claimed and the old one released in ONE transaction: if the new slot is taken, nothing changes. */
    public function reschedule(string $bookingId, ?int $expectedRowVersion, CarbonImmutable $start, CarbonImmutable $end, ?string $reason = null): Booking
    {
        return Tx::run(function () use ($bookingId, $expectedRowVersion, $start, $end, $reason) {
            if (Ids::isUuid($bookingId) && ($rid = DB::table('booking')->where('id', Ids::toBinary($bookingId))->value('resource_id')) !== null) {
                $this->lockResource(Ids::fromBinary($rid)); // lock order: resource before booking
            }
            $b = $this->lock($bookingId, $expectedRowVersion);
            if (! in_array($b->status, [Booking::CONFIRMED, Booking::HELD], true)) {
                throw ApiProblem::conflict('booking_state_invalid', "A {$b->status} booking cannot be rescheduled.");
            }
            if ($b->status === Booking::HELD && ! $b->isHoldLive()) {
                throw ApiProblem::conflict('hold_expired', 'The hold expired; place a new hold.');
            }
            $resource = $b->resource;
            $rules = $this->rules->for($resource);
            $now = CarbonImmutable::now('UTC');
            if ($b->status === Booking::CONFIRMED) {
                if ($b->start_at < $now->addMinutes((int) $rules['reschedule_cutoff_minutes'])) {
                    throw ApiProblem::conflict('booking_state_invalid', 'Too close to the start time to reschedule.', ['meta' => ['reason' => 'reschedule_cutoff']]);
                }
                if ($b->reschedule_count >= (int) $rules['max_reschedules']) {
                    throw ApiProblem::conflict('booking_state_invalid', 'The maximum number of reschedules was reached.', ['meta' => ['reason' => 'max_reschedules']]);
                }
            }
            $oldSlots = count($this->grid->slotStartsFor($resource, $b->start_at, $b->end_at) ?? [1]);
            $starts = $this->grid->slotStartsFor($resource, $start, $end);
            if ($starts === null) {
                throw ApiProblem::unprocessable('validation_failed', 'The requested time is not a run of this resource\'s slots.', ['start' => ['must align to the resource slot grid']]);
            }
            if (count($starts) !== $oldSlots) {
                throw ApiProblem::unprocessable('validation_failed', 'A reschedule must keep the same number of slots (price is unchanged).', ['end' => ['duration must not change']]);
            }
            if ($start < $now->addMinutes((int) $rules['min_notice_minutes']) || $start > $now->addDays((int) $rules['max_advance_days'])) {
                throw ApiProblem::conflict('slot_unavailable', 'That time is outside the bookable window.', ['meta' => ['reason' => 'booking_window']]);
            }
            $this->assertNotBlackedOut($resource, $start, $end);

            $item = BookingItem::query()->where('booking_id', $b->id)->firstOrFail();
            $plan = $this->policy->plan($resource, new HoldCommand($resource->id, $start, $end, $b->quantity, $b->whole_resource, null, $b->source, null, null), forceOffline: false);
            if ($plan->delegateToCloud) {
                $plan = new AllocationPlan($b->allocation_pool, 1, $resource->unitCount(), false, 'reschedule keeps the booking\'s pool');
            }
            $this->releaseAllocations($b->id); // row locks held until commit; a failed allocate below rolls this back
            $expires = $b->status === Booking::HELD ? $b->hold_expires_at : null;
            $this->allocate($resource, $plan, $item->id, $starts, $end, $b->quantity, $b->whole_resource, $expires);
            if ($b->status === Booking::CONFIRMED) {
                DB::table('slot_allocation')->where('booking_item_id', Ids::toBinary($item->id))->update(['status' => 'CONFIRMED', 'hold_expires_at' => null]);
            }
            DB::table('booking_item')->where('id', Ids::toBinary($item->id))->update(['slot_start' => $start->format(self::FMT), 'slot_end' => $end->format(self::FMT)]);
            DB::table('booking')->where('id', Ids::toBinary($b->id))->update([
                'start_at' => $start->format(self::FMT), 'end_at' => $end->format(self::FMT), 'reschedule_count' => DB::raw('reschedule_count + 1'), 'row_version' => DB::raw('row_version + 1'),
            ]);
            $fresh = Booking::query()->with('resource')->findOrFail($b->id);
            $this->entitlements->rescheduleForBooking($fresh);

            Audit::record('booking.reschedule', 'Booking', $b->id, ['start' => $b->start_at->format('Y-m-d\TH:i:s\Z'), 'end' => $b->end_at->format('Y-m-d\TH:i:s\Z')],
                ['start' => $start->format('Y-m-d\TH:i:s\Z'), 'end' => $end->format('Y-m-d\TH:i:s\Z'), 'reason' => $reason], organizationId: $b->organization_id, siteId: $b->site_id, facilityUnitId: $b->facility_unit_id);
            Outbox::record('BookingRescheduled', 'Booking', $b->id, [
                'bookingId' => $b->id, 'resourceId' => $b->resource_id, 'old' => ['start' => $b->start_at->format('Y-m-d\TH:i:s\Z'), 'end' => $b->end_at->format('Y-m-d\TH:i:s\Z')],
                'new' => ['start' => $start->format('Y-m-d\TH:i:s\Z'), 'end' => $end->format('Y-m-d\TH:i:s\Z')], 'reason' => $reason,
            ], entityVersion: $fresh->row_version, organizationId: $b->organization_id, siteId: $b->site_id, facilityId: $b->facility_unit_id);

            return $fresh;
        });
    }

    // --------------------------------------------------------------------------------------------- ATTACH ORDER

    /**
     * Reception flow: link the order that carries the slot fee (+ rentals + store items) to a live hold, so that when the
     * order is paid (Payments -> `PaymentCaptured`) the booking is confirmed and ONE QR entitlement issued in that same
     * transaction (ConfirmBookingsForPaidOrder). Extends the hold so the cashier has a full TTL to take payment.
     */
    public function attachOrder(string $bookingId, ?int $expectedRowVersion, string $orderId): Booking
    {
        return Tx::run(function () use ($bookingId, $expectedRowVersion, $orderId) {
            $b = $this->lock($bookingId, $expectedRowVersion);
            if (! in_array($b->status, [Booking::HELD, Booking::PENDING_PAYMENT], true) || ! $b->isHoldLive()) {
                throw ApiProblem::conflict($b->status === Booking::EXPIRED || ! $b->isHoldLive() ? 'hold_expired' : 'booking_state_invalid', 'An order can only be attached to a live hold.');
            }
            if (! Schema::hasTable('order_line')) {
                throw ApiProblem::unprocessable('validation_failed', 'Attaching an order requires the Orders module.', ['orderId' => ['orders module not installed']]);
            }
            $orderBin = Ids::toBinary($orderId);
            $order = DB::table('order')->where('id', $orderBin)->lockForUpdate()->first();
            if ($order === null || Ids::fromBinary($order->organization_id) !== $b->organization_id) {
                throw ApiProblem::notFound('not_found', 'Order not found.');
            }
            if (in_array($order->status, ['VOIDED', 'PENDING_APPROVAL', 'SETTLED'], true)) {
                throw ApiProblem::conflict('order_state_invalid', "An order that is {$order->status} cannot be attached to a booking.");
            }
            $taken = DB::table('booking')->where('order_id', $orderBin)->whereNotIn('status', ['CANCELLED', 'EXPIRED'])->where('id', '!=', Ids::toBinary($b->id))->exists();
            if ($taken) {
                throw ApiProblem::conflict('booking_state_invalid', 'That order is already attached to another booking.');
            }
            $resource = $b->resource;
            if ($resource->product_id !== null) {
                $fee = DB::table('order_line')->where('order_id', $orderBin)->where('product_id', Ids::toBinary($resource->product_id))->whereNotIn('status', ['VOIDED', 'REMOVED'])->sum('line_total');
                if (Money::of((string) $fee)->compare(Money::of($b->total)) !== 0) {
                    throw new ApiProblem(422, 'amount_mismatch', 'The order lines for this resource do not add up to the booking total.', 'Amount mismatch', ['meta' => ['expected' => Money::of($b->total)->amount, 'orderLines' => Money::of((string) $fee)->amount]]);
                }
            } elseif (Money::of($order->total)->compare(Money::of($b->total)) < 0) {
                throw new ApiProblem(422, 'amount_mismatch', 'The order total is below the booking total.', 'Amount mismatch', ['meta' => ['expected' => Money::of($b->total)->amount, 'orderTotal' => Money::of($order->total)->amount]]);
            }

            $ttl = (int) $this->rules->for($resource)['hold_ttl_seconds'];
            $expires = CarbonImmutable::now('UTC')->addSeconds($ttl)->format(self::FMT);
            DB::table('booking')->where('id', Ids::toBinary($b->id))->update(['order_id' => $orderBin, 'status' => 'PENDING_PAYMENT', 'hold_expires_at' => $expires, 'row_version' => DB::raw('row_version + 1')]);
            $itemIds = DB::table('booking_item')->where('booking_id', Ids::toBinary($b->id))->pluck('id')->all();
            DB::table('slot_allocation')->whereIn('booking_item_id', $itemIds)->update(['hold_expires_at' => $expires]);
            Audit::record('booking.order.attach', 'Booking', $b->id, ['orderId' => $b->order_id], ['orderId' => $orderId, 'status' => 'PENDING_PAYMENT'], organizationId: $b->organization_id, siteId: $b->site_id, facilityUnitId: $b->facility_unit_id);

            return Booking::query()->with('resource')->findOrFail($b->id);
        });
    }

    // ------------------------------------------------------------------------------------------------ EXPIRY

    /** Sweep unpaid holds past their TTL (scheduler: `booking:expire-holds`, every minute). @return int holds expired */
    public function expireDue(int $limit = 500): int
    {
        $ids = DB::table('booking')->whereIn('status', ['HELD', 'PENDING_PAYMENT'])->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', CarbonImmutable::now('UTC')->format(self::FMT))->orderBy('hold_expires_at')->limit($limit)->pluck('id');
        $n = 0;
        foreach ($ids as $bin) {
            $n += Tx::run(fn () => $this->expireBooking(Ids::fromBinary($bin))) ? 1 : 0;
        }

        return $n;
    }

    /** Expire ONE booking if (and only if) it is still an unpaid, past-TTL hold; frees its slots. */
    public function expireBooking(string $bookingId): bool
    {
        $bin = Ids::toBinary($bookingId);
        $updated = DB::table('booking')->where('id', $bin)->whereIn('status', ['HELD', 'PENDING_PAYMENT'])
            ->where('hold_expires_at', '<', CarbonImmutable::now('UTC')->format(self::FMT))
            ->update(['status' => 'EXPIRED', 'row_version' => DB::raw('row_version + 1')]);
        if ($updated === 0) {
            return false;
        }
        $this->releaseAllocations($bookingId);
        event(new BookingHoldExpired($bookingId));

        return true;
    }

    // -------------------------------------------------------------------------------------------- INTERNALS

    /** Delete a booking's allocation rows BY PRIMARY KEY (record locks only, no gap-lock deadlocks). */
    public function releaseAllocations(string $bookingId): void
    {
        SlotAllocations::release($bookingId);
    }

    /**
     * Serialise allocation per resource with a row lock on the resource. The UNIQUE key stays the authoritative guard;
     * this makes contenders queue instead of deadlocking on the (UUIDv7, append-only) primary-key gap that failed
     * duplicate-key INSERTs leave locked. Lock order everywhere: resource, then booking, then allocation rows.
     */
    public function lockResource(string $resourceId): void
    {
        DB::table('bookable_resource')->where('id', Ids::toBinary($resourceId))->lockForUpdate()->value('id');
    }

    private function lock(string $bookingId, ?int $expectedRowVersion): Booking
    {
        if (! Ids::isUuid($bookingId)) {
            throw ApiProblem::notFound('not_found', 'Booking not found.');
        }
        $b = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
        if ($b === null || (($org = Tenant::organizationId()) !== null && $b->organization_id !== $org)) {
            throw ApiProblem::notFound('not_found', 'Booking not found.');
        }
        if ($expectedRowVersion !== null && $b->row_version !== $expectedRowVersion) {
            throw new ApiProblem(412, 'concurrency_conflict', 'The booking changed since you loaded it; reload and retry.', 'Precondition failed', ['meta' => ['currentRowVersion' => $b->row_version]]);
        }
        $b->load('resource');

        return $b;
    }

    /** @return array{0: int, 1: bool} quantity units and whether the whole resource is claimed */
    private function normaliseQuantity(BookableResource $resource, HoldCommand $cmd): array
    {
        $qty = max(1, $cmd->quantity);
        if ($resource->mode !== BookableResource::MODE_CAPACITY) {
            if ($qty !== 1) {
                throw ApiProblem::unprocessable('validation_failed', 'This resource is booked one at a time (quantity must be 1).', ['quantity' => ['must be 1']]);
            }

            return [1, false];
        }
        if ($cmd->wholeResource) {
            if (! $resource->allow_whole_resource) {
                throw ApiProblem::unprocessable('validation_failed', 'This resource cannot be booked as a whole.', ['wholeResource' => ['not allowed']]);
            }

            return [$resource->capacity, true];
        }
        if ($qty > $resource->capacity) {
            throw ApiProblem::conflict('slot_unavailable', 'Requested quantity exceeds the resource capacity.', ['meta' => ['reason' => 'capacity_exceeded']]);
        }

        return [$qty, false];
    }

    /** @return array{0: string, 1: string} [unit price per slot, line total] as money strings */
    private function price(BookableResource $resource, int $slots, int $qty, bool $whole): array
    {
        $perSlot = $whole
            ? ($resource->whole_price !== null ? Money::of($resource->whole_price) : Money::of($resource->price)->mul($resource->capacity))
            : Money::of($resource->price)->mul($qty);

        return [Money::of($whole ? $perSlot->amount : $resource->price)->amount, $perSlot->mul($slots)->amount];
    }

    private function assertNotBlackedOut(BookableResource $resource, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $hit = Blackout::query()->where('starts_at', '<', $end->format(self::FMT))->where('ends_at', '>', $start->format(self::FMT))
            ->where(fn ($q) => $q->where('resource_id', $resource->id)->orWhere('facility_unit_id', $resource->facility_unit_id))->first();
        if ($hit !== null) {
            throw ApiProblem::conflict('slot_unavailable', 'The resource is closed for that time.', ['meta' => ['reason' => 'blackout', 'blackoutReason' => $hit->reason]]);
        }
    }

    /** Snapshot other nodes consume (BookingSyncApplier::applySnapshot understands exactly this shape). @return array<string, mixed> */
    public function syncPayload(Booking $b, ?string $entitlementId = null): array
    {
        return app(BookingSyncApplier::class)->snapshot($b, $entitlementId);
    }
}
