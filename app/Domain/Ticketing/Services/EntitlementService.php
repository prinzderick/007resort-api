<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Models\EntitlementItem;
use App\Domain\Ticketing\Models\TicketType;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Entitlement issuance (architecture/11 §3). One model for "the holder of this code may do X":
 *  - INDIVIDUAL ticket type: 3 children + 2 adults -> 5 entitlements, each ONE ACCESS item (qty 1);
 *  - COMBINED: one entitlement, one ACCESS item with qty = n;
 *  - booking-based: one entitlement per booking (ACCESS for the resource's facility + optional RENTAL/GOODS items).
 * Issuance is IDEMPOTENT per source (`entitlement.source_key` UNIQUE): issuing again returns the existing one.
 * Must run inside the caller's DB transaction (audit + outbox are written with the rows).
 */
final class EntitlementService
{
    public function __construct(private readonly QrTokens $tokens) {}

    /**
     * Issue the entitlement of a CONFIRMED booking. `$extraItems` are RENTAL/GOODS/SERVICE items bought in the same
     * Reception transaction: [{kind, name, qty, facilityUnitId?, productId?, orderLineId?}].
     *
     * @param  list<array<string, mixed>>  $extraItems
     */
    public function issueForBooking(Booking $booking, array $extraItems = [], ?string $issuedBy = null): Entitlement
    {
        $resource = $booking->resource;
        $type = $resource->ticket_type_id ? TicketType::query()->find($resource->ticket_type_id) : null;
        $early = $type?->early_entry_minutes ?? (int) config('booking.defaults.early_entry_minutes');
        $qty = $booking->whole_resource ? max($booking->quantity, 1) : ($resource->mode === 'INDIVIDUAL_CAPACITY' ? $booking->quantity : 1);
        $mode = $type?->validation_mode ?? ($qty > 1 ? 'MULTIPLE_ENTRY' : 'SINGLE_USE');
        $local = $booking->start_at->setTimezone(config('booking.timezone', 'Africa/Lagos'));

        $items = [[
            'kind' => EntitlementItem::ACCESS,
            'name' => $resource->name.' - '.$local->format('H:i'),
            'qty' => $qty,
            'facilityUnitId' => $resource->facility_unit_id,
            'ticketTypeId' => $type?->id,
            'validationMode' => $mode,
            'validFrom' => $booking->start_at->subMinutes($early),
            'validUntil' => $booking->end_at,
        ]];
        foreach ($extraItems as $extra) {
            $items[] = $extra;
        }

        return $this->issue(
            sourceKey: 'booking:'.$booking->id,
            organizationId: $booking->organization_id,
            siteId: $booking->site_id,
            items: $items,
            bookingId: $booking->id,
            customerId: $booking->customer_id,
            holderName: $booking->customer_name,
            issuedBy: $issuedBy,
        );
    }

    /**
     * Issue tickets for an order's TICKET / RENTAL lines. `$lines` = OrderLineSource lines. Returns ALL entitlements
     * created (or already existing) for the order, idempotently.
     *
     * @param  list<array{lineId: string, productId: string, name: string, kind: string, quantity: int, facilityId: ?string}>  $lines
     * @return list<Entitlement>
     */
    public function issueForOrderLines(string $orderId, string $organizationId, string $siteId, array $lines, ?string $holderName, ?string $issuedBy = null): array
    {
        $out = [];
        $rentals = [];
        foreach ($lines as $line) {
            if ($line['kind'] === 'RENTAL') {
                $rentals[] = [
                    'kind' => EntitlementItem::RENTAL, 'name' => $line['name'], 'qty' => $line['quantity'], 'facilityUnitId' => $line['facilityId'],
                    'productId' => $line['productId'], 'orderLineId' => $line['lineId'], 'validationMode' => 'NONE',
                ];

                continue;
            }
            if ($line['kind'] !== 'TICKET') {
                continue;
            }
            $type = TicketType::query()->where('product_id', $line['productId'])->where('is_active', 1)->first();
            $facility = $type?->facility_unit_id ?? $line['facilityId'];
            [$from, $until] = $this->window($type, CarbonImmutable::now('UTC'));
            $base = [
                'kind' => EntitlementItem::ACCESS, 'name' => $line['name'], 'facilityUnitId' => $facility, 'ticketTypeId' => $type?->id,
                'productId' => $line['productId'], 'orderLineId' => $line['lineId'], 'validFrom' => $from, 'validUntil' => $until,
            ];
            if (($type?->format ?? 'INDIVIDUAL') === 'COMBINED') {
                $mode = $type?->validation_mode ?? 'MULTIPLE_ENTRY';
                $out[] = $this->issue("order:{$orderId}:line:{$line['lineId']}", $organizationId, $siteId, [$base + ['qty' => $line['quantity'], 'validationMode' => $mode]], orderId: $orderId, holderName: $holderName, issuedBy: $issuedBy);
            } else {
                for ($i = 1; $i <= $line['quantity']; $i++) {
                    $out[] = $this->issue("order:{$orderId}:line:{$line['lineId']}:{$i}", $organizationId, $siteId, [$base + ['qty' => 1, 'validationMode' => $type?->validation_mode ?? 'SINGLE_USE']], orderId: $orderId, holderName: $holderName, issuedBy: $issuedBy);
                }
            }
        }
        if ($rentals !== []) {
            // Rentals ride on ONE entitlement per order (what Sports Store releases against one QR).
            $out[] = $this->issue("order:{$orderId}:rentals", $organizationId, $siteId, $rentals, orderId: $orderId, holderName: $holderName, issuedBy: $issuedBy);
        }

        return $out;
    }

    /**
     * Generic, idempotent issue. Returns the existing entitlement when `sourceKey` was already issued.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function issue(
        string $sourceKey,
        string $organizationId,
        string $siteId,
        array $items,
        ?string $bookingId = null,
        ?string $orderId = null,
        ?string $customerId = null,
        ?string $holderName = null,
        ?string $issuedBy = null,
    ): Entitlement {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('EntitlementService::issue must run inside a DB transaction.');
        }
        if ($existing = Entitlement::query()->where('source_key', $sourceKey)->first()) {
            return $existing->load('items');
        }
        if ($items === []) {
            throw ApiProblem::unprocessable('validation_failed', 'An entitlement needs at least one item.');
        }

        $id = Ids::uuid7();
        try {
            Entitlement::create([ // a lost race on source_key fails just this statement (MySQL statement-level rollback)
                'id' => $id, 'organization_id' => $organizationId, 'site_id' => $siteId, 'qr_token' => $this->tokens->generate(),
                'source_key' => $sourceKey, 'status' => 'ACTIVE', 'booking_id' => $bookingId, 'order_id' => $orderId,
                'customer_id' => $customerId, 'holder_name' => $holderName, 'issued_by' => $issuedBy ?? RequestContext::staffId(),
                'issued_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return Entitlement::query()->where('source_key', $sourceKey)->sharedLock()->firstOrFail()->load('items'); // current read: the winner just committed
            }
            throw $e;
        }

        foreach (array_values($items) as $n => $spec) {
            $qty = $spec['qty'];
            if ($qty <= 0) {
                throw ApiProblem::unprocessable('validation_failed', 'Item quantity must be positive.');
            }
            EntitlementItem::create([
                'entitlement_id' => $id, 'kind' => $spec['kind'], 'name' => $spec['name'],
                'facility_unit_id' => $spec['facilityUnitId'] ?? null, 'ticket_type_id' => $spec['ticketTypeId'] ?? null,
                'product_id' => $spec['productId'] ?? null, 'order_line_id' => $spec['orderLineId'] ?? null,
                'validation_mode' => $spec['validationMode'] ?? 'NONE', 'qty' => $qty,
                'valid_from' => $spec['validFrom'] ?? null, 'valid_until' => $spec['validUntil'] ?? null, 'sort_order' => $n,
            ]);
        }

        $entitlement = Entitlement::query()->findOrFail($id)->load('items');
        Audit::record('ticket.issue', 'Entitlement', $id, null, ['sourceKey' => $sourceKey, 'items' => $entitlement->items->count(), 'bookingId' => $bookingId, 'orderId' => $orderId],
            organizationId: $organizationId, siteId: $siteId);
        Outbox::record('EntitlementIssued', 'Entitlement', $id, app(EntitlementSyncApplier::class)->snapshot($entitlement), organizationId: $organizationId, siteId: $siteId);

        return $entitlement;
    }

    /** Move an ACCESS item's validity window with its booking (reschedule). */
    public function rescheduleForBooking(Booking $booking): void
    {
        $ent = Entitlement::query()->where('booking_id', $booking->id)->first();
        if ($ent === null) {
            return;
        }
        $resource = $booking->resource;
        $type = $resource->ticket_type_id ? TicketType::query()->find($resource->ticket_type_id) : null;
        $early = $type?->early_entry_minutes ?? (int) config('booking.defaults.early_entry_minutes');
        $local = $booking->start_at->setTimezone(config('booking.timezone', 'Africa/Lagos'));
        EntitlementItem::query()->where('entitlement_id', $ent->id)->where('kind', EntitlementItem::ACCESS)->update([
            'valid_from' => $booking->start_at->subMinutes($early)->format('Y-m-d H:i:s.u'),
            'valid_until' => $booking->end_at->format('Y-m-d H:i:s.u'),
            'name' => $resource->name.' - '.$local->format('H:i'),
        ]);
    }

    /** Cancel a booking's entitlement. Refused (409 ticket_used) if any quantity was already consumed. */
    public function cancelForBooking(string $bookingId): void
    {
        $ent = Entitlement::query()->where('booking_id', $bookingId)->lockForUpdate()->first();
        if ($ent === null || $ent->status === 'CANCELLED') {
            return;
        }
        if (EntitlementItem::query()->where('entitlement_id', $ent->id)->where('qty_redeemed', '>', 0)->exists()) {
            throw ApiProblem::conflict('ticket_used', 'The ticket has already been used or items released; the booking cannot be cancelled.');
        }
        DB::table('entitlement')->where('id', Ids::toBinary($ent->id))->update([
            'status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'row_version' => DB::raw('row_version + 1'),
        ]);
        Audit::record('ticket.cancel', 'Entitlement', $ent->id, ['status' => $ent->status], ['status' => 'CANCELLED'], organizationId: $ent->organization_id, siteId: $ent->site_id);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} valid_from / valid_until for a non-booking ticket type */
    private function window(?TicketType $type, CarbonImmutable $issuedAt): array
    {
        $kind = $type?->validity_kind ?? 'ISSUE_DAY';
        $tz = config('booking.timezone', 'Africa/Lagos');
        if ($kind === 'DURATION_MINUTES' && $type?->validity_minutes) {
            return [$issuedAt, $issuedAt->addMinutes($type->validity_minutes)];
        }

        return [$issuedAt->setTimezone($tz)->startOfDay()->utc()->toImmutable(), $issuedAt->setTimezone($tz)->endOfDay()->utc()->toImmutable()];
    }
}
