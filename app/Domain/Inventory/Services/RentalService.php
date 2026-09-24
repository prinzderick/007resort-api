<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\RentalGateway;
use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RentalService implements RentalGateway
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function issueAsset(string $assetIdOrTag, string $referenceType, string $referenceId, ?string $actorStaffId = null): array
    {
        $asset = $this->find($assetIdOrTag);
        $actor = $actorStaffId ?? RequestContext::staffId();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        $affected = DB::update(
            "UPDATE rental_asset SET status = 'ISSUED', issued_reference_type = ?, issued_reference_id = ?, issued_at = ?, issued_by = ?, returned_at = NULL,
                    row_version = row_version + 1
             WHERE id = ? AND status = 'AVAILABLE'",
            [$referenceType, Ids::toBinary($referenceId), $now, $actor ? Ids::toBinary($actor) : null, $asset->id],
        );
        if ($affected !== 1) {
            // Same reference re-issuing the same asset is a replay, not a conflict.
            $cur = DB::table('rental_asset')->where('id', $asset->id)->sharedLock()->first(); // latest committed, not this txn's snapshot
            if ($cur->status === 'ISSUED' && $cur->issued_reference_type === $referenceType && $cur->issued_reference_id === Ids::toBinary($referenceId)) {
                return $this->dto($cur, true);
            }
            throw ApiProblem::conflict('concurrency_conflict', "Asset {$asset->asset_tag} is not available ({$cur->status}).", ['meta' => ['assetId' => Ids::fromBinary($asset->id), 'status' => $cur->status]]);
        }

        return $this->dto(DB::table('rental_asset')->where('id', $asset->id)->first(), false);
    }

    public function returnAsset(string $assetIdOrTag, ?string $conditionNote = null, bool $damaged = false, ?string $actorStaffId = null): array
    {
        $asset = $this->find($assetIdOrTag);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $affected = DB::update(
            "UPDATE rental_asset SET status = ?, returned_at = ?, condition_note = COALESCE(?, condition_note), row_version = row_version + 1
             WHERE id = ? AND status = 'ISSUED'",
            [$damaged ? 'MAINTENANCE' : 'AVAILABLE', $now, $conditionNote, $asset->id],
        );
        if ($affected !== 1) {
            $cur = DB::table('rental_asset')->where('id', $asset->id)->sharedLock()->first(); // latest committed, not this txn's snapshot
            if (in_array($cur->status, ['AVAILABLE', 'MAINTENANCE'], true)) {
                return $this->dto($cur, true); // already returned
            }
            throw ApiProblem::conflict('concurrency_conflict', "Asset {$asset->asset_tag} is not currently issued ({$cur->status}).", ['meta' => ['status' => $cur->status]]);
        }

        return $this->dto(DB::table('rental_asset')->where('id', $asset->id)->first(), false);
    }

    public function issueQuantity(string $locationId, string $itemId, string $quantity, string $referenceType, string $referenceId, ?string $lineId = null, ?string $actorStaffId = null): array
    {
        return $this->moveQuantity('RENTAL_OUT', Qty::neg(Qty::normalize($quantity)), $locationId, $itemId, $referenceType, $referenceId, $lineId, $actorStaffId);
    }

    public function returnQuantity(string $locationId, string $itemId, string $quantity, string $referenceType, string $referenceId, ?string $lineId = null, ?string $actorStaffId = null): array
    {
        return $this->moveQuantity('RENTAL_IN', Qty::normalize($quantity), $locationId, $itemId, $referenceType, $referenceId, $lineId, $actorStaffId);
    }

    private function moveQuantity(string $reason, string $delta, string $locationId, string $itemId, string $refType, string $refId, ?string $lineId, ?string $actor): array
    {
        if (! Qty::isPositive(Qty::abs($delta))) {
            throw ApiProblem::unprocessable('validation_failed', 'Quantity must be positive.');
        }
        $r = $this->ledger->post(new MovementSpec($itemId, $locationId, $delta, $reason, $refType, $refId, $lineId,
            dedupeKey: $reason.':'.$refId.':'.($lineId ?? $itemId).':'.$itemId, actorStaffId: $actor));

        return ['movementId' => $r['id'], 'balanceAfter' => $r['balanceAfter'], 'replayed' => $r['replayed']];
    }

    private function find(string $idOrTag): object
    {
        $q = DB::table('rental_asset');
        $row = Ids::isUuid($idOrTag) ? $q->where('id', Ids::toBinary($idOrTag))->first() : $q->where('asset_tag', $idOrTag)->first();
        if (! $row) {
            throw ApiProblem::notFound('not_found', 'That rental asset does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function dto(object $r, bool $replayed = false): array
    {
        return [
            'id' => Ids::fromBinary($r->id), 'itemId' => Ids::fromBinary($r->item_id), 'locationId' => Ids::fromBinary($r->location_id),
            'assetTag' => $r->asset_tag, 'status' => $r->status,
            'issuedReferenceType' => $r->issued_reference_type, 'issuedReferenceId' => $r->issued_reference_id ? Ids::fromBinary($r->issued_reference_id) : null,
            'issuedAt' => $r->issued_at ? CarbonImmutable::parse($r->issued_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z') : null,
            'returnedAt' => $r->returned_at ? CarbonImmutable::parse($r->returned_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z') : null,
            'conditionNote' => $r->condition_note, 'rowVersion' => (int) $r->row_version, 'replayed' => $replayed,
        ];
    }
}
