<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Services\DbOrderPort;

/**
 * What Payments needs from the Orders module (module boundary, docs/MODULES.md: a module talks to another through its
 * Services/Events). The default binding is {@see DbOrderPort}: it READS `order`/`tab`/`order_line`
 * (allowed: foreign-key style lookups) and delegates every WRITE to Orders' `OrderSettlementService` when that class exists.
 *
 * Locking contract: lockTab/lockOrders MUST use SELECT ... FOR UPDATE and MUST be called before any other read in the
 * transaction (Payments' serialisation of concurrent settlements depends on it). Lock order everywhere: tab, then orders
 * sorted by id.
 */
interface OrderPort
{
    /**
     * @return null|array{id: string, facilityId: string, status: string, orderIds: list<string>} null if the tab does not exist.
     *                                                                                            `orderIds` = every order attached to the tab (any status).
     */
    public function lockTab(string $tabId): ?array;

    /**
     * Lock the given orders (FOR UPDATE, ascending id). Orders that do not exist are absent from the result.
     *
     * @param  list<string>  $orderIds
     * @return array<string, array{id: string, number: string, facilityId: string, paymentFacilityId: ?string, status: string, total: string, subtotal: string, discountTotal: string, taxTotal: string, currency: string, tabId: ?string}>
     */
    public function lockOrders(array $orderIds): array;

    /**
     * Render data (names, quantities, prices) for receipts and result summaries. Plain read.
     *
     * @param  list<string>  $orderIds
     * @return array<string, array{tableLabel: ?string, lineCount: int, lines: list<array{name: string, quantity: int, unitPrice: string, lineTotal: string}>}>
     */
    public function details(array $orderIds): array;

    /**
     * Money has been allocated to the order. `$settled` = allocations now cover the whole total.
     * Orders implements this as `OrderSettlementService::markSettled` (status -> SETTLED, amount_paid, audit, outbox).
     */
    public function applyPayment(string $orderId, string $amountPaid, bool $settled, string $paymentGroupId): void;

    /** A payment was reversed: the order's paid amount drops to `$amountPaid` and, if it was SETTLED, it is re-opened. */
    public function applyReversal(string $orderId, string $amountPaid, string $paymentGroupId): void;

    /** Every order on the tab is settled. */
    public function markTabSettled(string $tabId): void;
}
