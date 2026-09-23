<?php

namespace App\Domain\Inventory\Contracts;

use App\Support\Http\ApiProblem;

/**
 * Hooks for Ticketing's Sports Store release / return (architecture/11). Call inside your transaction.
 *  - TAGGED assets (rental_asset rows): atomic status compare-and-set, so one asset can never be released twice.
 *  - POOLED items (no tags): quantity moves through the ledger (RENTAL_OUT / RENTAL_IN), guarded like any deduction.
 * Every method is idempotent per (reference, id).
 */
interface RentalGateway
{
    /** @throws ApiProblem rental_asset_unavailable (409) when not AVAILABLE */
    public function issueAsset(string $assetIdOrTag, string $referenceType, string $referenceId, ?string $actorStaffId = null): array;

    /** @param  bool  $damaged  true routes the asset to MAINTENANCE instead of AVAILABLE */
    public function returnAsset(string $assetIdOrTag, ?string $conditionNote = null, bool $damaged = false, ?string $actorStaffId = null): array;

    /** @throws ApiProblem insufficient_stock (409) */
    public function issueQuantity(string $locationId, string $itemId, string $quantity, string $referenceType, string $referenceId, ?string $lineId = null, ?string $actorStaffId = null): array;

    public function returnQuantity(string $locationId, string $itemId, string $quantity, string $referenceType, string $referenceId, ?string $lineId = null, ?string $actorStaffId = null): array;
}
