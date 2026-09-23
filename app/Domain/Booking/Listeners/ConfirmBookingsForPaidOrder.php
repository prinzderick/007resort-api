<?php

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Services\BookingService;
use App\Domain\Ticketing\Contracts\OrderLineSource;
use App\Domain\Ticketing\Listeners\IssueEntitlementsForPaidOrder;
use App\Domain\Ticketing\Services\OrderTicketing;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Payments/Orders -> Booking. Runs INSIDE the capturing transaction (Reception flow: hold slot -> order lines
 * [slot fee + rentals + store items] -> payment -> confirm the booking + issue ONE QR entitlement, atomically).
 *
 *  - `PaymentCaptured{subjectType:'BOOKING', subjectId}`  (online/Paystack payment of the booking itself): confirm it;
 *  - any event carrying orderIds (`PaymentCaptured`, `OrderSettled`): every unpaid booking linked to a now FULLY PAID order
 *    (booking.order_id, see BookingService::attachOrder) is confirmed, and the order's RENTAL / store-GOODS lines become
 *    extra items of the booking's entitlement.
 * Idempotent: an already CONFIRMED booking is left alone. A stale hold makes this throw `hold_expired`, which rolls the
 * payment back (no money is taken for a slot the customer no longer holds).
 */
final class ConfirmBookingsForPaidOrder
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly OrderLineSource $orders,
        private readonly OrderTicketing $tickets,
    ) {}

    public function handle(object $event): void
    {
        if (($event->subjectType ?? null) === 'BOOKING' && is_string($event->subjectId ?? null)) {
            $this->bookings->confirmPaid($event->subjectId, Money::of($event->amount ?? '0')->amount, null);
        }
        foreach (IssueEntitlementsForPaidOrder::orderIds($event) as $orderId) {
            $this->confirmForOrder($orderId);
        }
    }

    public function confirmForOrder(string $orderId): void
    {
        $ids = DB::table('booking')->where('order_id', Ids::toBinary($orderId))->whereIn('status', ['HELD', 'PENDING_PAYMENT'])->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $order = $this->orders->forOrder($orderId);
        if ($order === null || ! $order['fullyPaid']) {
            return; // partly paid: nothing is confirmed until the order is covered
        }
        $extras = $this->tickets->bookingExtras($orderId);
        foreach ($ids as $bin) {
            $bookingId = Ids::fromBinary($bin);
            $total = DB::table('booking')->where('id', $bin)->value('total');
            $this->bookings->confirmPaid($bookingId, Money::of($total)->amount, $orderId, $extras);
        }
    }
}
