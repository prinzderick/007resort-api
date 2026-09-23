<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Ticketing\Contracts\RentalStockHook;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Models\EntitlementItem;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scans, exits, rental release/return (architecture/11). THE invariant: quantities only change through
 * conditional single-row UPDATEs (`... WHERE qty_redeemed + :n <= qty`); InnoDB serialises them, so of N concurrent
 * scanners exactly one gets VALID and the rest get USED, regardless of timing or retries. Redemption rows are
 * append-only. Every scan writes a validation_event; the audit row and the outbox event are written in the SAME
 * transaction as the quantity change.
 *
 * Callers wrap in a transaction when they need one (the `idempotent` route middleware does); every public method here
 * opens its own if none is active.
 */
final class RedemptionService
{
    public function __construct(
        private readonly QrTokens $tokens,
        private readonly PermissionChecker $permissions,
        private readonly RentalStockHook $stock,
    ) {}

    public function lookup(string $qrToken): Entitlement
    {
        if (! $this->tokens->isWellFormed($qrToken)) {
            throw ApiProblem::notFound('ticket_invalid', 'Unknown ticket.');
        }
        $ent = Entitlement::query()->where('qr_token', $qrToken)->first();
        if ($ent === null) {
            throw ApiProblem::notFound('ticket_invalid', 'Unknown ticket.');
        }

        return $ent->load('items');
    }

    /**
     * Scan ENTRY. Always returns a result array (HTTP 200) for a known token; unknown/malformed -> 404 ticket_invalid.
     *
     * @return array<string, mixed> RedeemResult
     */
    public function redeem(string $qrToken, ?string $facilityId = null, ?string $entitlementItemId = null, int $quantity = 1, bool $override = false): array
    {
        return DB::transaction(function () use ($qrToken, $facilityId, $entitlementItemId, $quantity, $override) {
            $ent = $this->lookup($qrToken);
            $scanFacility = $this->requireFacility($facilityId, 'ticket.redeem');
            $now = CarbonImmutable::now('UTC');

            $decide = $this->decide($ent, $scanFacility, $this->facilityChain($scanFacility), $entitlementItemId, $now);
            if (isset($decide['result'])) {
                return $this->finishScan($ent, $decide['item'] ?? null, 'ENTRY', $scanFacility, $decide['result'], $decide['message'], $decide['extra'] ?? [], $now);
            }
            /** @var EntitlementItem $item */
            $item = $decide['item'];

            if ($item->validation_mode === 'NONE') {
                return $this->finishScan($ent, $item, 'ENTRY', $scanFacility, 'VALID', 'No validation required', ['remaining' => (int) ($item->qty - $item->qty_redeemed)], $now);
            }
            if ($item->validation_mode === 'STAFF_APPROVAL') {
                $approved = $override && $this->permissions->can((string) RequestContext::staffId(), 'ticket.override', Scope::facility($scanFacility));
                if (! $approved) {
                    throw new ApiProblem(409, 'approval_required', 'This ticket needs staff approval; a holder of ticket.override must confirm (override=true).', 'Approval required', ['meta' => ['permission' => 'ticket.override', 'entitlementItemId' => $item->id]]);
                }
                Audit::record('ticket.override', 'EntitlementItem', $item->id, null, ['entitlementId' => $ent->id, 'facilityId' => $scanFacility], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityUnitId: $scanFacility);
            }

            $bin = Ids::toBinary($item->id);
            $inside = $item->validation_mode === 'ENTRY_EXIT';
            if ($item->validation_mode === 'SINGLE_USE') {
                // First successful scan consumes the whole item (qty may be >1 for a group ticket admitted by one scan).
                $affected = DB::update('UPDATE entitlement_item SET qty_redeemed = qty, qty_inside = 0 WHERE id = ? AND qty_redeemed = 0', [$bin]);
                $n = (string) $item->qty;
            } else {
                $n = (string) max(1, $quantity);
                $affected = DB::update(
                    'UPDATE entitlement_item SET qty_redeemed = qty_redeemed + ?, qty_inside = qty_inside + ? WHERE id = ? AND qty_redeemed + ? <= qty',
                    [$n, $inside ? $n : '0', $bin, $n],
                );
            }

            if ($affected === 0) {
                $last = DB::table('redemption')->where('entitlement_item_id', $bin)->where('action', 'ENTRY')->orderByDesc('created_at')->first(['created_at']);
                $usedAt = $last ? CarbonImmutable::parse($last->created_at, 'UTC') : null;
                $fresh = EntitlementItem::query()->find($item->id);

                return $this->finishScan($ent, $item, 'ENTRY', $scanFacility, 'USED', $usedAt ? 'Already used at '.$usedAt->setTimezone(config('booking.timezone'))->format('H:i') : 'Already used', [
                    'usedAt' => $usedAt?->format('Y-m-d\TH:i:s\Z'), 'remaining' => (int) max(0, $fresh->qty - $fresh->qty_redeemed),
                ], $now);
            }

            $redemptionId = Ids::uuid7();
            DB::table('redemption')->insert([
                'id' => Ids::toBinary($redemptionId), 'entitlement_item_id' => $bin, 'action' => 'ENTRY', 'qty' => $n,
                'device_id' => $this->bin(ScanContext::deviceId()), 'staff_id' => $this->bin(RequestContext::staffId()),
                'facility_unit_id' => Ids::toBinary($scanFacility), 'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
            $fresh = EntitlementItem::query()->find($item->id);
            $this->refreshExhausted($ent);
            Audit::record('ticket.redeem', 'EntitlementItem', $item->id, ['qtyRedeemed' => (string) $item->qty_redeemed], ['qtyRedeemed' => (string) $fresh->qty_redeemed, 'action' => 'ENTRY', 'qty' => $n],
                organizationId: $ent->organization_id, siteId: $ent->site_id, facilityUnitId: $scanFacility);
            Outbox::record('TicketRedeemed', 'EntitlementItem', $item->id, [
                'entitlementId' => $ent->id, 'entitlementItemId' => $item->id, 'action' => 'ENTRY', 'qty' => $n, 'redemptionId' => $redemptionId,
                'facilityId' => $scanFacility, 'deviceId' => ScanContext::deviceId(), 'staffId' => RequestContext::staffId(), 'at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            ], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityId: $scanFacility);

            return $this->finishScan($ent, $item, 'ENTRY', $scanFacility, 'VALID', 'Welcome', [
                'remaining' => (int) max(0, $fresh->qty - $fresh->qty_redeemed), 'redemptionId' => $redemptionId,
            ], $now);
        });
    }

    /**
     * Record EXIT for ENTRY_EXIT tickets (headcount-in-venue). An EXIT needs an open ENTRY (`qty_inside >= 1`).
     *
     * @return array<string, mixed>
     */
    public function exit(string $qrToken, ?string $facilityId = null, ?string $entitlementItemId = null): array
    {
        return DB::transaction(function () use ($qrToken, $facilityId, $entitlementItemId) {
            $ent = $this->lookup($qrToken);
            $scanFacility = $this->requireFacility($facilityId, 'ticket.redeem');
            $now = CarbonImmutable::now('UTC');

            if ($ent->status === 'CANCELLED') {
                return $this->finishScan($ent, null, 'EXIT', $scanFacility, 'CANCELLED', 'Ticket cancelled', [], $now);
            }
            $item = $ent->items->first(fn ($i) => $i->kind === 'ACCESS' && $i->validation_mode === 'ENTRY_EXIT'
                && ($entitlementItemId === null || $i->id === $entitlementItemId)
                && ($i->facility_unit_id === null || in_array($i->facility_unit_id, $this->facilityChain($scanFacility), true)));
            if ($item === null) {
                throw ApiProblem::conflict('ticket_invalid', 'This ticket has no entry/exit item for this facility.');
            }
            $bin = Ids::toBinary($item->id);
            if (DB::update('UPDATE entitlement_item SET qty_inside = qty_inside - 1 WHERE id = ? AND qty_inside >= 1', [$bin]) === 0) {
                throw ApiProblem::conflict('ticket_invalid', 'No open entry to exit (holder is not inside).');
            }
            $redemptionId = Ids::uuid7();
            DB::table('redemption')->insert([
                'id' => Ids::toBinary($redemptionId), 'entitlement_item_id' => $bin, 'action' => 'EXIT', 'qty' => '1',
                'device_id' => $this->bin(ScanContext::deviceId()), 'staff_id' => $this->bin(RequestContext::staffId()),
                'facility_unit_id' => Ids::toBinary($scanFacility), 'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
            Outbox::record('TicketRedeemed', 'EntitlementItem', $item->id, [
                'entitlementId' => $ent->id, 'entitlementItemId' => $item->id, 'action' => 'EXIT', 'qty' => '1', 'redemptionId' => $redemptionId,
                'facilityId' => $scanFacility, 'deviceId' => ScanContext::deviceId(), 'staffId' => RequestContext::staffId(), 'at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            ], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityId: $scanFacility);

            return $this->finishScan($ent, $item, 'EXIT', $scanFacility, 'VALID', 'Exit recorded', ['redemptionId' => $redemptionId, 'remaining' => (int) max(0, $item->qty - $item->qty_redeemed)], $now);
        });
    }

    /**
     * Release rental items to the customer. Whole item quantity; duplicate release is impossible (conditional UPDATE).
     * All-or-nothing across `$itemIds`: if any item was already released the whole request fails 409 `ticket_used`.
     *
     * @param  list<string>  $itemIds
     */
    public function release(string $entitlementId, array $itemIds, ?string $note = null, ?string $depositCollected = null): Entitlement
    {
        return DB::transaction(function () use ($entitlementId, $itemIds, $note, $depositCollected) {
            [$ent, $items, $facility] = $this->rentalContext($entitlementId, $itemIds, 'ticket.release');
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            foreach ($items as $item) {
                $bin = Ids::toBinary($item->id);
                if (DB::update('UPDATE entitlement_item SET qty_redeemed = qty WHERE id = ? AND qty_redeemed = 0', [$bin]) === 0) {
                    throw ApiProblem::conflict('ticket_used', "'{$item->name}' has already been released.", ['meta' => ['entitlementItemId' => $item->id]]);
                }
                $rid = Ids::uuid7();
                DB::table('redemption')->insert([
                    'id' => Ids::toBinary($rid), 'entitlement_item_id' => $bin, 'action' => 'RELEASE', 'qty' => (string) $item->qty,
                    'device_id' => $this->bin(ScanContext::deviceId()), 'staff_id' => $this->bin(RequestContext::staffId()),
                    'facility_unit_id' => $this->bin($facility ?? $item->facility_unit_id), 'amount' => $depositCollected === null ? null : Money::of($depositCollected)->amount,
                    'note' => $note === null ? null : mb_substr($note, 0, 255), 'created_at' => $now,
                ]);
                $this->stock->rentalOut(['entitlementItemId' => $item->id, 'entitlementId' => $ent->id, 'productId' => $item->product_id, 'facilityUnitId' => $item->facility_unit_id, 'quantity' => (string) $item->qty, 'staffId' => RequestContext::staffId()]);
                Audit::record('ticket.rental.release', 'EntitlementItem', $item->id, ['qtyRedeemed' => '0'], ['qtyRedeemed' => (string) $item->qty, 'note' => $note], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityUnitId: $facility ?? $item->facility_unit_id);
                Outbox::record('RentalReleased', 'EntitlementItem', $item->id, [
                    'entitlementId' => $ent->id, 'entitlementItemId' => $item->id, 'productId' => $item->product_id, 'qty' => (string) $item->qty,
                    'redemptionId' => $rid, 'deviceId' => ScanContext::deviceId(), 'staffId' => RequestContext::staffId(), 'at' => str_replace(' ', 'T', $now).'Z',
                ], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityId: $facility ?? $item->facility_unit_id);
            }
            $this->refreshExhausted($ent);

            return $ent->refresh()->load('items');
        });
    }

    /**
     * Take rental items back. Must have been released; a second return is impossible.
     *
     * @param  list<string>  $itemIds
     */
    public function return(string $entitlementId, array $itemIds, string $condition = 'OK', ?string $note = null, ?string $damageCharge = null): Entitlement
    {
        if (! in_array($condition, ['OK', 'DAMAGED', 'LOST'], true)) {
            throw ApiProblem::unprocessable('validation_failed', 'condition must be OK, DAMAGED or LOST.', ['condition' => ['invalid']]);
        }

        return DB::transaction(function () use ($entitlementId, $itemIds, $condition, $note, $damageCharge) {
            [$ent, $items, $facility] = $this->rentalContext($entitlementId, $itemIds, 'ticket.release', requireActive: false);
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            foreach ($items as $item) {
                $bin = Ids::toBinary($item->id);
                if (DB::update('UPDATE entitlement_item SET qty_returned = qty WHERE id = ? AND qty_redeemed = qty AND qty_returned = 0', [$bin]) === 0) {
                    $fresh = EntitlementItem::query()->find($item->id);
                    throw ApiProblem::conflict($fresh->qty_returned > 0 ? 'ticket_used' : 'ticket_invalid',
                        $fresh->qty_returned > 0 ? "'{$item->name}' has already been returned." : "'{$item->name}' has not been released yet.", ['meta' => ['entitlementItemId' => $item->id]]);
                }
                $rid = Ids::uuid7();
                DB::table('redemption')->insert([
                    'id' => Ids::toBinary($rid), 'entitlement_item_id' => $bin, 'action' => 'RETURN', 'qty' => (string) $item->qty,
                    'device_id' => $this->bin(ScanContext::deviceId()), 'staff_id' => $this->bin(RequestContext::staffId()),
                    'facility_unit_id' => $this->bin($facility ?? $item->facility_unit_id), 'item_condition' => $condition,
                    'amount' => $damageCharge === null ? null : Money::of($damageCharge)->amount, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'created_at' => $now,
                ]);
                $this->stock->rentalIn(['entitlementItemId' => $item->id, 'entitlementId' => $ent->id, 'productId' => $item->product_id, 'facilityUnitId' => $item->facility_unit_id, 'quantity' => (string) $item->qty, 'condition' => $condition, 'staffId' => RequestContext::staffId()]);
                Audit::record('ticket.rental.return', 'EntitlementItem', $item->id, ['qtyReturned' => '0'], ['qtyReturned' => (string) $item->qty, 'condition' => $condition, 'damageCharge' => $damageCharge], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityUnitId: $facility ?? $item->facility_unit_id);
                Outbox::record('RentalReturned', 'EntitlementItem', $item->id, [
                    'entitlementId' => $ent->id, 'entitlementItemId' => $item->id, 'productId' => $item->product_id, 'qty' => (string) $item->qty, 'condition' => $condition,
                    'damageCharge' => $damageCharge === null ? null : Money::of($damageCharge)->amount, 'redemptionId' => $rid, 'deviceId' => ScanContext::deviceId(),
                    'staffId' => RequestContext::staffId(), 'at' => str_replace(' ', 'T', $now).'Z',
                ], organizationId: $ent->organization_id, siteId: $ent->site_id, facilityId: $facility ?? $item->facility_unit_id);
            }

            return $ent->refresh()->load('items');
        });
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * Pure decision of everything BEFORE the atomic consume: CANCELLED / WRONG_FACILITY / NOT_YET_VALID / EXPIRED.
     *
     * @return array{result?: string, message?: string, item?: ?EntitlementItem, extra?: array<string, mixed>}
     */
    private function decide(Entitlement $ent, string $scanFacility, array $chain, ?string $itemId, CarbonImmutable $now): array
    {
        if ($ent->status === 'CANCELLED' || $this->parentCancelled($ent)) {
            return ['result' => 'CANCELLED', 'message' => 'Ticket cancelled'];
        }
        /** @var Collection<int, EntitlementItem> $access */
        $access = $ent->items->filter(fn ($i) => $i->kind === EntitlementItem::ACCESS && ($itemId === null || $i->id === $itemId))->values();
        if ($access->isEmpty()) {
            return ['result' => 'WRONG_FACILITY', 'message' => 'This ticket has no entry item', 'extra' => ['expectedFacilityId' => null]];
        }
        $here = $access->filter(fn ($i) => $i->facility_unit_id === null || in_array($i->facility_unit_id, $chain, true))->values();
        if ($here->isEmpty()) {
            $expected = $access->first();

            return ['result' => 'WRONG_FACILITY', 'message' => 'Wrong facility for this ticket', 'item' => $expected, 'extra' => ['expectedFacilityId' => $expected->facility_unit_id]];
        }
        $item = $here->first(fn ($i) => $i->qty_redeemed < $i->qty) ?? $here->first();
        if ($item->valid_from !== null && $now < $item->valid_from) {
            return ['result' => 'NOT_YET_VALID', 'message' => 'Not valid until '.$item->valid_from->setTimezone(config('booking.timezone'))->format('D H:i'), 'item' => $item, 'extra' => $this->window($item)];
        }
        if ($item->valid_until !== null && $now > $item->valid_until) {
            return ['result' => 'EXPIRED', 'message' => 'Ticket expired', 'item' => $item, 'extra' => $this->window($item)];
        }
        if ($item->validation_mode === 'TIME_LIMITED' && ($item->valid_from === null || $item->valid_until === null)) {
            return ['result' => 'EXPIRED', 'message' => 'Ticket has no validity window', 'item' => $item];
        }

        return ['item' => $item];
    }

    /**
     * The scanning facility and its ancestors: an item bound to "Sports Arena" is valid at the "Sports Entrance" gate
     * (a child of the arena) but not at the Pool. @return list<string>
     */
    private function facilityChain(string $facilityId): array
    {
        return $this->permissions->resolve(Scope::facility($facilityId))[2];
    }

    private function parentCancelled(Entitlement $ent): bool
    {
        if ($ent->booking_id === null) {
            return false;
        }
        $status = DB::table('booking')->where('id', Ids::toBinary($ent->booking_id))->value('status');

        return in_array($status, ['CANCELLED', 'EXPIRED'], true);
    }

    /** @return array{validFrom: ?string, validUntil: ?string} */
    private function window(EntitlementItem $i): array
    {
        return ['validFrom' => $i->valid_from?->format('Y-m-d\TH:i:s\Z'), 'validUntil' => $i->valid_until?->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * Write the validation_event (ALWAYS) and shape the RedeemResult.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function finishScan(Entitlement $ent, ?EntitlementItem $item, string $action, string $facility, string $result, string $message, array $extra, CarbonImmutable $now): array
    {
        DB::table('validation_event')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'entitlement_id' => Ids::toBinary($ent->id), 'entitlement_item_id' => $item ? Ids::toBinary($item->id) : null,
            'action' => $action, 'device_id' => $this->bin(ScanContext::deviceId()), 'staff_id' => $this->bin(RequestContext::staffId()),
            'facility_unit_id' => Ids::toBinary($facility), 'result' => $result, 'message' => mb_substr($message, 0, 255), 'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return array_merge([
            'result' => $result,
            'entitlementId' => $ent->id,
            'itemId' => $item?->id,
            'holderName' => $ent->holder_name,
            'itemName' => $item?->name,
            'remaining' => $item ? (int) max(0, $item->qty - $item->qty_redeemed) : 0,
            'redemptionId' => null,
            'message' => $message,
        ], $extra);
    }

    /** Resolve the scan facility and enforce `$permission` AT that facility (facility-scoped, permission-based). */
    private function requireFacility(?string $explicit, string $permission): string
    {
        $facility = ScanContext::facilityId($explicit);
        if ($facility === null) {
            throw ApiProblem::unprocessable('validation_failed', 'facilityId is required (this device has no checkout facility).', ['facilityId' => ['required']]);
        }
        $staff = RequestContext::staffId();
        if ($staff === null || ! $this->permissions->can($staff, $permission, Scope::facility($facility))) {
            throw ApiProblem::forbidden('permission_denied', "Missing permission: {$permission} at this facility.", ['permission' => $permission]);
        }

        return $facility;
    }

    /** @return array{0: Entitlement, 1: Collection<int, EntitlementItem>, 2: ?string} */
    private function rentalContext(string $entitlementId, array $itemIds, string $permission, bool $requireActive = true): array
    {
        $ent = Entitlement::query()->whereKey($entitlementId)->first();
        if ($ent === null) {
            throw ApiProblem::notFound('not_found', 'Entitlement not found.');
        }
        if ($requireActive && $ent->status === 'CANCELLED') {
            throw ApiProblem::conflict('ticket_invalid', 'The entitlement is cancelled.');
        }
        $facility = ScanContext::facilityId(null);
        if ($facility !== null) {
            $staff = RequestContext::staffId();
            if ($staff === null || ! $this->permissions->can($staff, $permission, Scope::facility($facility))) {
                throw ApiProblem::forbidden('permission_denied', "Missing permission: {$permission} at this facility.", ['permission' => $permission]);
            }
        }
        $ids = array_values(array_unique($itemIds));
        sort($ids); // consistent lock order
        if ($ids === []) {
            throw ApiProblem::unprocessable('validation_failed', 'itemIds is required.', ['itemIds' => ['required']]);
        }
        $items = EntitlementItem::query()->where('entitlement_id', $ent->id)->whereIn('id', $ids)->orderBy('id')->get();
        if ($items->count() !== count($ids)) {
            throw ApiProblem::notFound('not_found', 'One or more items do not belong to this entitlement.');
        }
        foreach ($items as $item) {
            if ($item->kind !== EntitlementItem::RENTAL) {
                throw ApiProblem::unprocessable('validation_failed', "'{$item->name}' is not a rental item.", ['itemIds' => ['not a rental item']]);
            }
            if ($facility !== null && $item->facility_unit_id !== null && $item->facility_unit_id !== $facility) {
                throw ApiProblem::conflict('facility_mismatch', "'{$item->name}' is handled at a different facility.");
            }
        }

        return [$ent, $items, $facility];
    }

    /** Mark the entitlement EXHAUSTED once every item's full quantity has been consumed. */
    private function refreshExhausted(Entitlement $ent): void
    {
        DB::update(
            "UPDATE entitlement e SET e.status = 'EXHAUSTED', e.row_version = e.row_version + 1
              WHERE e.id = ? AND e.status = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM entitlement_item i WHERE i.entitlement_id = e.id AND i.qty_redeemed < i.qty)",
            [Ids::toBinary($ent->id)],
        );
    }

    private function bin(?string $uuid): ?string
    {
        return $uuid === null ? null : Ids::toBinary($uuid);
    }
}
