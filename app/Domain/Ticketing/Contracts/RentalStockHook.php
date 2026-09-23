<?php

namespace App\Domain\Ticketing\Contracts;

/**
 * Inventory's rental hooks (spec §8 "Rental equipment issue/return"; stock reasons RENTAL_OUT / RENTAL_IN).
 * The Inventory module binds an implementation; the default is a no-op so Ticketing works without it.
 * Called INSIDE the release/return transaction, so a stock failure rolls the release back too.
 */
interface RentalStockHook
{
    /** @param array{entitlementItemId: string, entitlementId: string, productId: ?string, facilityUnitId: ?string, quantity: string, staffId: ?string} $context */
    public function rentalOut(array $context): void;

    /** @param array{entitlementItemId: string, entitlementId: string, productId: ?string, facilityUnitId: ?string, quantity: string, condition: string, staffId: ?string} $context */
    public function rentalIn(array $context): void;
}
