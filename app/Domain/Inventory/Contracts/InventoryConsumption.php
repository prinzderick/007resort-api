<?php

namespace App\Domain\Inventory\Contracts;

use App\Domain\Inventory\Support\ConsumptionLine;
use App\Domain\Inventory\Support\ConsumptionResult;
use App\Support\Http\ApiProblem;

/**
 * How Orders (and anything else that sells / uses stock) talks to Inventory. Call these INSIDE your own DB
 * transaction: they take row locks on stock_balance, write ledger rows (+ Outbox StockConsumed) and either
 * apply everything or throw {@see ApiProblem} `insufficient_stock` (409, meta.itemId) so your
 * transaction rolls back and the order line is rejected.
 *
 * Idempotent: every line carries a stable `lineRef` (e.g. the order-line id); replaying the same call, an
 * OrderSent event delivered twice, or two racing requests never deduct a line twice (UNIQUE dedupe key).
 */
interface InventoryConsumption
{
    /**
     * Deduct stock for a sale from the facility's default sale location (or a line's explicit locationId).
     *
     * @param  list<ConsumptionLine>  $lines  item-level (Orders/Catalog resolves product -> item via product_stock_link)
     *
     * @throws ApiProblem insufficient_stock (409) when a location disallows negative stock
     */
    public function consume(string $facilityUnitId, array $lines, string $referenceType, string $referenceId, ?string $actorStaffId = null): ConsumptionResult;

    /**
     * Put back what was consumed (order/line void). Reverses every not-yet-reversed SALE movement of the reference
     * (or only those of the given line refs). Idempotent per original movement.
     *
     * @param  list<string>|null  $lineRefs
     */
    public function reverse(string $referenceType, string $referenceId, ?array $lineRefs = null, ?string $actorStaffId = null): ConsumptionResult;

    /**
     * Would this sale fit right now? (advisory only — the guarded UPDATE in consume() is the authority).
     *
     * @param  list<ConsumptionLine>  $lines
     * @return list<array{itemId: string, locationId: string, requested: string, available: string}> shortages (empty = ok)
     */
    public function shortages(string $facilityUnitId, array $lines): array;
}
