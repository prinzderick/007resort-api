<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Domain\Ticketing\Models\Entitlement;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Turns a PAID order into entitlements. Idempotent (per order line, see EntitlementService), safe to call from any
 * number of listeners: PaymentCaptured, OrderSettled, manual `POST /entitlements {orderId}`.
 *
 * Rules:
 *  - only orders that are fully paid (amount_paid >= total) and not voided / pending approval;
 *  - TICKET lines -> individual (one entitlement per person) or combined, per the ticket type;
 *  - RENTAL lines -> ONE rentals entitlement per order — UNLESS a booking is linked to the order (Reception flow), in which
 *    case they ride on the booking's entitlement so the customer shows ONE QR (see bookingExtras()).
 */
final class OrderTicketing
{
    public function __construct(private readonly OrderLineSource $orders, private readonly EntitlementService $entitlements) {}

    /** @return list<Entitlement> */
    public function issueForPaidOrder(string $orderId, ?string $issuedBy = null): array
    {
        $order = $this->orders->forOrder($orderId);
        if ($order === null || ! $order['fullyPaid']) {
            return [];
        }
        $linkedToBooking = DB::table('booking')->where('order_id', Ids::toBinary($orderId))->whereNotIn('status', ['CANCELLED', 'EXPIRED'])->exists();
        $lines = array_values(array_filter($order['lines'], fn ($l) => $l['kind'] === 'TICKET' || ($l['kind'] === 'RENTAL' && ! $linkedToBooking)));
        if ($lines === []) {
            return [];
        }

        return $this->entitlements->issueForOrderLines($orderId, $order['organizationId'], $order['siteId'], $lines, $order['holderName'], $issuedBy);
    }

    /**
     * RENTAL / store-GOODS lines of an order as extra items for a booking's entitlement.
     *
     * @return list<array<string, mixed>>
     */
    public function bookingExtras(string $orderId): array
    {
        $order = $this->orders->forOrder($orderId);
        $extras = [];
        foreach ($order['lines'] ?? [] as $l) {
            if ($l['kind'] === 'RENTAL') {
                $extras[] = ['kind' => 'RENTAL', 'name' => $l['name'], 'qty' => $l['quantity'], 'facilityUnitId' => $l['facilityId'], 'productId' => $l['productId'], 'orderLineId' => $l['lineId'], 'validationMode' => 'NONE'];
            } elseif ($l['storeAvailable']) {
                $extras[] = ['kind' => 'GOODS', 'name' => $l['name'], 'qty' => $l['quantity'], 'facilityUnitId' => $l['facilityId'], 'productId' => $l['productId'], 'orderLineId' => $l['lineId'], 'validationMode' => 'NONE'];
            }
        }

        return $extras;
    }
}
