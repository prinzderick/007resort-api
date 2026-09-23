<?php

namespace App\Domain\Ticketing\Listeners;

use App\Domain\Ticketing\Services\OrderTicketing;

/**
 * Issues entitlements when an order is paid. Subscribed (TicketingServiceProvider) to:
 *   - Payments' `PaymentCaptured` (fired inside the capturing transaction; carries orderIds), and
 *   - Orders' `OrderSettled` (fallback when an order becomes SETTLED).
 * Runs INSIDE the payment/settlement transaction: entitlement + audit + outbox commit or roll back with the money.
 * Duck-typed on the event so the module does not hard-depend on Payments' class.
 */
final class IssueEntitlementsForPaidOrder
{
    public function __construct(private readonly OrderTicketing $tickets) {}

    public function handle(object $event): void
    {
        foreach (self::orderIds($event) as $orderId) {
            $this->tickets->issueForPaidOrder($orderId);
        }
    }

    /** @return list<string> */
    public static function orderIds(object $event): array
    {
        if (property_exists($event, 'orderIds') && is_array($event->orderIds)) {
            return array_values(array_unique(array_map('strval', $event->orderIds)));
        }

        return property_exists($event, 'orderId') && is_string($event->orderId) ? [$event->orderId] : [];
    }
}
