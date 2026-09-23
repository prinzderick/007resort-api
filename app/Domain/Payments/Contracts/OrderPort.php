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
     * `$delta` was allocated to the order by a captured payment (`$paidAfter` = ledger total now). `$fullyPaid` = it now covers the
     * whole total. Implemented with Orders' `OrderSettlementService::applyPayment` (+ `markSettled` when fully paid).
     *
     * @return string the order's status after the call (SETTLED once served and fully paid; pay-first orders stay DRAFT/SENT)
     */
    public function applyPayment(string $orderId, string $delta, string $paidAfter, bool $fullyPaid, string $paymentGroupId): string;

    /**
     * A payment was reversed: `$delta` is taken back off the order (Orders re-opens a SETTLED order as SERVED).
     *
     * @return string the order's status after the call
     */
    public function applyReversal(string $orderId, string $delta, string $paymentGroupId): string;

    /** Every order on the tab is paid: close it (Orders settles the orders that are served, closes the tab, frees the table). */
    public function markTabSettled(string $tabId): void;
}
