<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Support\MovementDocument;
use App\Domain\Inventory\Support\MovementSpec;
use App\Domain\Inventory\Support\Qty;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Purchase receipts, transfers, wastage and returns. Each posts its ledger legs, writes its document rows, an Audit row
 * and an Outbox event in ONE transaction — a failure anywhere (e.g. the 3rd transfer line has no stock) leaves nothing.
 */
class StockDocuments
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array{locationId: string, supplierId?: ?string, supplierName?: ?string, supplierInvoice?: ?string, note?: ?string, lines: list<array{itemId: string, quantity: string, unitCost?: mixed}>}  $data
     * @return array<string, mixed> StockMovement
     */
    public function receive(array $data, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();

        return DB::transaction(function () use ($data, $actor) {
            $location = $this->ledger->location($data['locationId']);
            $org = Ids::fromBinary($location->organization_id);
            $supplierId = $this->resolveSupplier($org, $data['supplierId'] ?? null, $data['supplierName'] ?? null);

            $receiptId = Ids::uuid7();
            DB::table('purchase_receipt')->insert([
                'id' => Ids::toBinary($receiptId), 'organization_id' => Ids::toBinary($org), 'location_id' => Ids::toBinary($data['locationId']),
                'supplier_id' => $supplierId ? Ids::toBinary($supplierId) : null, 'supplier_invoice' => $data['supplierInvoice'] ?? null,
                'total_cost' => '0', 'note' => $data['note'] ?? null, 'received_by' => $actor ? Ids::toBinary($actor) : null,
            ]);

            $specs = [];
            $total = '0.0000';
            $lineDocs = [];
            foreach ($data['lines'] as $line) {
                $qty = Qty::normalize($line['quantity']);
                if (! Qty::isPositive($qty)) {
                    throw ApiProblem::unprocessable('validation_failed', 'Receipt quantities must be positive.');
                }
                $cost = $this->cost($line['unitCost'] ?? null);
                $lineId = Ids::uuid7();
                DB::table('purchase_receipt_line')->insert([
                    'id' => Ids::toBinary($lineId), 'receipt_id' => Ids::toBinary($receiptId), 'item_id' => Ids::toBinary($line['itemId']),
                    'quantity' => $qty, 'unit_cost' => $cost,
                ]);
                if ($cost !== null) {
                    $total = Qty::add($total, Qty::mul($qty, $cost));
                }
                $lineDocs[] = ['itemId' => $line['itemId'], 'quantity' => $qty, 'unitCost' => $cost];
                $specs[] = new MovementSpec($line['itemId'], $data['locationId'], $qty, 'RECEIPT', 'purchase_receipt', $receiptId, $lineId,
                    unitCost: $cost, note: $data['supplierInvoice'] ?? null, actorStaffId: $actor);
            }
            DB::table('purchase_receipt')->where('id', Ids::toBinary($receiptId))->update(['total_cost' => $total]);
            $results = $this->ledger->postMany($specs);

            Audit::record('inventory.receipt', 'PurchaseReceipt', $receiptId, null, [
                'locationId' => $data['locationId'], 'supplierId' => $supplierId, 'supplierInvoice' => $data['supplierInvoice'] ?? null,
                'totalCost' => $total, 'lines' => $lineDocs,
            ], facilityUnitId: $this->facilityOf($location));
            Outbox::record('StockReceived', 'PurchaseReceipt', $receiptId, [
                'receiptId' => $receiptId, 'locationId' => $data['locationId'], 'supplierId' => $supplierId, 'lines' => $lineDocs,
            ], facilityId: $this->facilityOf($location));

            return MovementDocument::make('PURCHASE_RECEIPT', $receiptId, 'POSTED', null, $this->legs($results, $specs));
        });
    }

    /**
     * @param  array{fromLocationId: string, toLocationId: string, note?: ?string, lines: list<array{itemId: string, quantity: string, unitCost?: mixed}>}  $data
     * @return array<string, mixed> StockMovement
     */
    public function transfer(array $data, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();
        if ($data['fromLocationId'] === $data['toLocationId']) {
            throw ApiProblem::unprocessable('validation_failed', 'Source and destination must differ.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $from = $this->ledger->location($data['fromLocationId']);
            $to = $this->ledger->location($data['toLocationId']);
            if ($from->organization_id !== $to->organization_id) {
                throw ApiProblem::unprocessable('validation_failed', 'Locations belong to different organizations.');
            }
            $org = Ids::fromBinary($from->organization_id);

            $transferId = Ids::uuid7();
            DB::table('stock_transfer')->insert([
                'id' => Ids::toBinary($transferId), 'organization_id' => Ids::toBinary($org),
                'from_location_id' => Ids::toBinary($data['fromLocationId']), 'to_location_id' => Ids::toBinary($data['toLocationId']),
                'status' => 'POSTED', 'note' => $data['note'] ?? null, 'created_by' => $actor ? Ids::toBinary($actor) : null,
            ]);

            $specs = [];
            $lineDocs = [];
            foreach ($data['lines'] as $line) {
                $qty = Qty::normalize($line['quantity']);
                if (! Qty::isPositive($qty)) {
                    throw ApiProblem::unprocessable('validation_failed', 'Transfer quantities must be positive.');
                }
                $cost = $this->cost($line['unitCost'] ?? null);
                $lineId = Ids::uuid7();
                DB::table('stock_transfer_line')->insert([
                    'id' => Ids::toBinary($lineId), 'transfer_id' => Ids::toBinary($transferId), 'item_id' => Ids::toBinary($line['itemId']),
                    'quantity' => $qty, 'unit_cost' => $cost,
                ]);
                $lineDocs[] = ['itemId' => $line['itemId'], 'quantity' => $qty];
                // Paired legs: OUT (guarded) + IN. Same document/line reference.
                $specs[] = new MovementSpec($line['itemId'], $data['fromLocationId'], Qty::neg($qty), 'TRANSFER_OUT', 'stock_transfer', $transferId, $lineId,
                    counterpartLocationId: $data['toLocationId'], unitCost: $cost, note: $data['note'] ?? null, actorStaffId: $actor);
                $specs[] = new MovementSpec($line['itemId'], $data['toLocationId'], $qty, 'TRANSFER_IN', 'stock_transfer', $transferId, $lineId,
                    counterpartLocationId: $data['fromLocationId'], unitCost: $cost, note: $data['note'] ?? null, actorStaffId: $actor);
            }
            $results = $this->ledger->postMany($specs);

            Audit::record('inventory.transfer', 'StockTransfer', $transferId, null, [
                'fromLocationId' => $data['fromLocationId'], 'toLocationId' => $data['toLocationId'], 'lines' => $lineDocs,
            ], facilityUnitId: $this->facilityOf($to));
            Outbox::record('StockTransferred', 'StockTransfer', $transferId, [
                'transferId' => $transferId, 'fromLocationId' => $data['fromLocationId'], 'toLocationId' => $data['toLocationId'], 'lines' => $lineDocs,
            ], facilityId: $this->facilityOf($to) ?? $this->facilityOf($from));

            return MovementDocument::make('TRANSFER_OUT', $transferId, 'POSTED', null, $this->legs($results, $specs));
        });
    }

    /**
     * @param  array{locationId: string, itemId: string, quantity: string, reason: string, note?: ?string}  $data
     * @return array<string, mixed>
     */
    public function wastage(array $data, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();
        $qty = Qty::normalize($data['quantity']);
        if (! Qty::isPositive($qty)) {
            throw ApiProblem::unprocessable('validation_failed', 'Wastage quantity must be positive.');
        }

        return DB::transaction(function () use ($data, $actor, $qty) {
            $location = $this->ledger->location($data['locationId']);
            $docId = Ids::uuid7();
            $note = $data['reason'].(($data['note'] ?? '') !== '' ? ': '.$data['note'] : '');
            $spec = new MovementSpec($data['itemId'], $data['locationId'], Qty::neg($qty), 'WASTAGE', 'wastage', $docId, note: $note, actorStaffId: $actor);
            $results = $this->ledger->postMany([$spec]);

            $payload = ['locationId' => $data['locationId'], 'itemId' => $data['itemId'], 'quantity' => $qty, 'reason' => $data['reason'], 'note' => $data['note'] ?? null];
            Audit::record('inventory.wastage', 'Wastage', $docId, null, $payload, facilityUnitId: $this->facilityOf($location));
            Outbox::record('StockAdjusted', 'Wastage', $docId, $payload + ['quantityDelta' => Qty::neg($qty), 'kind' => 'WASTAGE'], facilityId: $this->facilityOf($location));

            return MovementDocument::make('WASTAGE', $docId, 'POSTED', null, $this->legs($results, [$spec]));
        });
    }

    /**
     * Customer return (restock, `CUSTOMER`) or return to supplier (`SUPPLIER`).
     *
     * @param  array{locationId: string, itemId: string, quantity: string, kind: string, note?: ?string}  $data
     * @return array<string, mixed>
     */
    public function recordReturn(array $data, ?string $actor = null): array
    {
        $actor ??= RequestContext::staffId();
        $qty = Qty::normalize($data['quantity']);
        if (! Qty::isPositive($qty)) {
            throw ApiProblem::unprocessable('validation_failed', 'Return quantity must be positive.');
        }
        $supplier = $data['kind'] === 'SUPPLIER';

        return DB::transaction(function () use ($data, $actor, $qty, $supplier) {
            $location = $this->ledger->location($data['locationId']);
            $docId = Ids::uuid7();
            $delta = $supplier ? Qty::neg($qty) : $qty;
            $spec = new MovementSpec($data['itemId'], $data['locationId'], $delta, $supplier ? 'SUPPLIER_RETURN' : 'CUSTOMER_RETURN', 'stock_return', $docId,
                note: $data['note'] ?? null, actorStaffId: $actor);
            $results = $this->ledger->postMany([$spec]);

            $payload = ['locationId' => $data['locationId'], 'itemId' => $data['itemId'], 'quantityDelta' => $delta, 'kind' => $data['kind'], 'note' => $data['note'] ?? null];
            Audit::record('inventory.return', 'StockReturn', $docId, null, $payload, facilityUnitId: $this->facilityOf($location));
            Outbox::record('StockAdjusted', 'StockReturn', $docId, $payload, facilityId: $this->facilityOf($location));

            return MovementDocument::make('RETURN', $docId, 'POSTED', null, $this->legs($results, [$spec]));
        });
    }

    /** @return list<array{itemId: string, locationId: string, quantityDelta: string, unitCost: ?string}> */
    private function legs(array $results, array $specs): array
    {
        $out = [];
        foreach ($results as $i => $r) {
            $out[] = ['itemId' => $r['itemId'], 'locationId' => $r['locationId'], 'quantityDelta' => $r['delta'], 'unitCost' => $specs[$i]->unitCost];
        }

        return $out;
    }

    private function facilityOf(object $location): ?string
    {
        return $location->facility_unit_id ? Ids::fromBinary($location->facility_unit_id) : null;
    }

    /** Accepts a Money object {amount,currency} or a plain decimal string; NGN only. */
    private function cost(mixed $unitCost): ?string
    {
        if ($unitCost === null || $unitCost === '') {
            return null;
        }
        if (is_array($unitCost)) {
            if (isset($unitCost['currency']) && strtoupper((string) $unitCost['currency']) !== 'NGN') {
                throw ApiProblem::unprocessable('validation_failed', 'Only NGN is supported.');
            }
            $unitCost = $unitCost['amount'] ?? null;
        }
        $amount = Money::normalize($unitCost);
        if (Qty::isNegative($amount)) {
            throw ApiProblem::unprocessable('validation_failed', 'Unit cost cannot be negative.');
        }

        return $amount;
    }

    private function resolveSupplier(string $org, ?string $supplierId, ?string $supplierName): ?string
    {
        if ($supplierId !== null) {
            if (! DB::table('supplier')->where('id', Ids::toBinary($supplierId))->where('organization_id', Ids::toBinary($org))->exists()) {
                throw ApiProblem::notFound('supplier_not_found', 'That supplier does not exist.');
            }

            return $supplierId;
        }
        $name = trim((string) $supplierName);
        if ($name === '') {
            return null;
        }
        $row = DB::table('supplier')->where('organization_id', Ids::toBinary($org))->where('name', $name)->first(['id']);
        if ($row) {
            return Ids::fromBinary($row->id);
        }
        $id = Ids::uuid7();
        DB::table('supplier')->insertOrIgnore(['id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'name' => $name]);
        $row = DB::table('supplier')->where('organization_id', Ids::toBinary($org))->where('name', $name)->first(['id']);

        return Ids::fromBinary($row->id);
    }
}
