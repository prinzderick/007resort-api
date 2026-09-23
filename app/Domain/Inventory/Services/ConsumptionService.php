<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Support\ConsumptionResult;
use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Stock consumption for sales (Orders -> Inventory). See {@see InventoryConsumption}.
 *
 * Dedupe keys: SALE:<lineRef>:<itemId> for the deduction and REV:<saleMovementId> for its reversal, so an event delivered
 * twice, an API retry, or two racing requests never deducts (or restores) the same line twice.
 */
class ConsumptionService implements InventoryConsumption
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function consume(string $facilityUnitId, array $lines, string $referenceType, string $referenceId, ?string $actorStaffId = null): ConsumptionResult
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('InventoryConsumption::consume must be called inside the caller\'s DB transaction.');
        }
        if ($lines === []) {
            return new ConsumptionResult([]);
        }

        $default = null;
        $specs = [];
        foreach ($lines as $line) {
            $qty = Qty::normalize($line->quantity);
            if (! Qty::isPositive($qty)) {
                throw ApiProblem::unprocessable('validation_failed', 'Consumption quantity must be positive.');
            }
            if (! Ids::isUuid($line->lineRef)) {
                throw new LogicException('ConsumptionLine::lineRef must be the order-line UUID.');
            }
            $locationId = $line->locationId ?? ($default ??= $this->saleLocation($facilityUnitId));
            $specs[] = new MovementSpec($line->itemId, $locationId, Qty::neg($qty), 'SALE', $referenceType, $referenceId, Ids::normalize($line->lineRef),
                dedupeKey: 'SALE:'.Ids::normalize($line->lineRef).':'.$line->itemId, actorStaffId: $actorStaffId);
        }

        $results = $this->ledger->postMany($specs);
        $movements = array_map(fn ($r) => [
            'movementId' => $r['id'], 'itemId' => $r['itemId'], 'locationId' => $r['locationId'], 'quantityDelta' => $r['delta'], 'replayed' => $r['replayed'],
        ], $results);

        if (array_filter($movements, fn ($m) => ! $m['replayed'])) {
            Outbox::record('StockConsumed', 'StockConsumption', $referenceId, [
                'referenceType' => $referenceType, 'referenceId' => $referenceId, 'facilityId' => $facilityUnitId,
                'lines' => array_map(fn ($m) => ['itemId' => $m['itemId'], 'locationId' => $m['locationId'], 'quantity' => Qty::abs($m['quantityDelta'])],
                    array_values(array_filter($movements, fn ($m) => ! $m['replayed']))),
            ], facilityId: $facilityUnitId);
        }

        return new ConsumptionResult($movements);
    }

    public function reverse(string $referenceType, string $referenceId, ?array $lineRefs = null, ?string $actorStaffId = null): ConsumptionResult
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('InventoryConsumption::reverse must be called inside the caller\'s DB transaction.');
        }
        $q = DB::table('stock_movement')->where('reference_type', $referenceType)->where('reference_id', Ids::toBinary($referenceId))->where('reason', 'SALE')
            ->orderBy('location_id')->orderBy('item_id');
        if ($lineRefs !== null) {
            $bins = array_map(fn ($r) => Ids::toBinary($r), array_filter($lineRefs, fn ($r) => Ids::isUuid($r)));
            $q->whereIn('reference_line_id', $bins);
        }
        $sales = $q->get();

        $specs = [];
        foreach ($sales as $s) {
            $specs[] = new MovementSpec(Ids::fromBinary($s->item_id), Ids::fromBinary($s->location_id), Qty::abs($s->qty_delta), 'SALE_RETURN', $referenceType, $referenceId,
                $s->reference_line_id ? Ids::fromBinary($s->reference_line_id) : null, unitCost: $s->unit_cost, note: 'Reversal of sale movement '.Ids::fromBinary($s->id),
                dedupeKey: 'REV:'.Ids::fromBinary($s->id), actorStaffId: $actorStaffId);
        }
        $results = $this->ledger->postMany($specs);
        $movements = array_map(fn ($r) => [
            'movementId' => $r['id'], 'itemId' => $r['itemId'], 'locationId' => $r['locationId'], 'quantityDelta' => $r['delta'], 'replayed' => $r['replayed'],
        ], $results);
        if (array_filter($movements, fn ($m) => ! $m['replayed'])) {
            Outbox::record('StockAdjusted', 'StockConsumption', $referenceId, [
                'referenceType' => $referenceType, 'referenceId' => $referenceId, 'kind' => 'SALE_RETURN',
                'lines' => array_map(fn ($m) => ['itemId' => $m['itemId'], 'locationId' => $m['locationId'], 'quantityDelta' => $m['quantityDelta']],
                    array_values(array_filter($movements, fn ($m) => ! $m['replayed']))),
            ]);
        }

        return new ConsumptionResult($movements);
    }

    public function shortages(string $facilityUnitId, array $lines): array
    {
        $default = null;
        $need = [];
        foreach ($lines as $line) {
            $locationId = $line->locationId ?? ($default ??= $this->saleLocation($facilityUnitId));
            $k = $locationId.'|'.$line->itemId;
            $need[$k] = Qty::add($need[$k] ?? '0', Qty::normalize($line->quantity));
        }
        $out = [];
        foreach ($need as $k => $requested) {
            [$locationId, $itemId] = explode('|', $k);
            $loc = $this->ledger->location($locationId);
            $available = $this->ledger->onHand($itemId, $locationId);
            if (! $loc->allow_negative && Qty::cmp($available, $requested) < 0) {
                $out[] = ['itemId' => $itemId, 'locationId' => $locationId, 'requested' => $requested, 'available' => $available];
            }
        }

        return $out;
    }

    /** The store a facility sells from: its own active location (default-flagged first), else the nearest ancestor's. */
    public function saleLocation(string $facilityUnitId): string
    {
        $cursor = $facilityUnitId;
        for ($depth = 0; $depth < 8 && $cursor !== null; $depth++) {
            $row = DB::table('stock_location')->where('facility_unit_id', Ids::toBinary($cursor))->where('is_active', 1)
                ->orderByDesc('is_sale_default')->orderBy('name')->first(['id']);
            if ($row) {
                return Ids::fromBinary($row->id);
            }
            $parent = DB::table('facility_unit')->where('id', Ids::toBinary($cursor))->value('parent_id');
            $cursor = $parent ? Ids::fromBinary($parent) : null;
        }

        throw ApiProblem::unprocessable('validation_failed', 'This facility has no stock location configured.', ['facilityId' => ['no stock location configured']]);
    }

    /** Facility rule `stock_consumption_timing` (SEND | SETTLE) from its INVENTORY capability's operating rules. */
    public function timingFor(string $facilityUnitId): string
    {
        $value = DB::table('operating_rule as r')
            ->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')
            ->where('c.facility_unit_id', Ids::toBinary($facilityUnitId))->where('c.capability_code', 'INVENTORY')->where('c.is_enabled', 1)
            ->where('r.rule_key', 'stock_consumption_timing')->value('r.rule_value');
        $value = strtoupper((string) ($value ?? config('inventory.default_consumption_timing', 'SEND')));

        return in_array($value, ['SEND', 'SETTLE'], true) ? $value : 'SEND';
    }
}
