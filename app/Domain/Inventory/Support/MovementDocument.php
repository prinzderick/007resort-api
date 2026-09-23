<?php

namespace App\Domain\Inventory\Support;

use Carbon\CarbonImmutable;

/** Shapes the contract's `StockMovement` (a document of one or more ledger legs). */
final class MovementDocument
{
    /**
     * @param  list<array{itemId: string, locationId: string, quantityDelta: string, unitCost?: ?string}>  $legs
     * @return array<string, mixed>
     */
    public static function make(string $kind, string $documentId, string $status, ?string $approvalId, array $legs, ?string $createdAt = null): array
    {
        return [
            'id' => $documentId,
            'kind' => $kind,
            'documentId' => $documentId,
            'status' => $status,
            'approvalId' => $approvalId,
            'lines' => array_map(fn (array $l) => [
                'itemId' => $l['itemId'],
                'locationId' => $l['locationId'],
                'quantityDelta' => $l['quantityDelta'],
                'unitCost' => isset($l['unitCost']) && $l['unitCost'] !== null ? ['amount' => $l['unitCost'], 'currency' => 'NGN'] : null,
            ], $legs),
            'createdAt' => ($createdAt !== null ? CarbonImmutable::parse($createdAt, 'UTC') : CarbonImmutable::now('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** Contract kind for a ledger reason. */
    public static function kindOf(string $reason): string
    {
        return match ($reason) {
            'RECEIPT' => 'PURCHASE_RECEIPT',
            'SALE', 'SALE_RETURN' => 'CONSUMPTION',
            'COUNT' => 'COUNT_VARIANCE',
            'RENTAL_OUT', 'RENTAL_IN' => 'RENTAL',
            'SUPPLIER_RETURN', 'CUSTOMER_RETURN' => 'RETURN',
            default => $reason,
        };
    }
}
