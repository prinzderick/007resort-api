<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\BookingPaymentGateway;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Ticketing\Services\ScanContext;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Contract path `POST /bookings/{id}/confirm {tenders, cashSessionId}` backed by the real Orders + Payments modules:
 *  - the booking's slot fee becomes an order (COUNTER channel) unless Reception already attached one (`POST /bookings/{id}/order`);
 *  - the tenders are captured against that order by Payments (cash session / receipt / ledger, all its rules);
 *  - Payments' `PaymentCaptured` fires inside that transaction and ConfirmBookingsForPaidOrder confirms the booking + issues the QR,
 *    so by the time capture() returns the booking is normally already CONFIRMED (BookingService::confirm handles both orders of events).
 * Bound only when both modules exist (BookingServiceProvider). Any failure (amount mismatch, no cash session, hold expired) rolls back
 * the whole confirm: no money without a booking, no booking without money.
 */
final class PaymentsBookingGateway implements BookingPaymentGateway
{
    public function capture(string $bookingId, string $total, array $tenders, ?string $cashSessionId, ?string $paystackReference): array
    {
        if ($paystackReference !== null) {
            throw ApiProblem::unprocessable('validation_failed', 'Online (Paystack) bookings are paid through POST /payments/paystack/initialize with bookingId; the booking is confirmed by the webhook.');
        }
        if ($tenders === []) {
            throw ApiProblem::unprocessable('validation_failed', 'tenders is required.', ['tenders' => ['required']]);
        }
        $b = Booking::query()->with('resource')->findOrFail($bookingId);
        $facility = ScanContext::facilityId(null) ?? $this->paymentFacility($b) ?? throw ApiProblem::unprocessable('validation_failed', 'No payment facility: check the device out at Reception.');
        $orderId = $b->order_id ?? $this->createOrder($b, $facility);
        $order = DB::table('order')->where('id', Ids::toBinary($orderId))->first();
        $due = Money::of($order->total)->sub(Money::of($order->amount_paid));

        $in = ['facilityId' => $facility, 'cashSessionId' => $cashSessionId, 'tabId' => null, 'customerName' => $b->customer_name,
            'allocations' => [['orderId' => $orderId, 'amount' => $due->amount]], 'tenders' => array_values($tenders)];
        foreach ($in['tenders'] as $i => $t) {
            $in['tenders'][$i]['fingerprint'] = PaymentService::fingerprint($in, $t, false);
        }
        $result = app(PaymentService::class)->create($in, (string) RequestContext::staffId());

        return ['amountPaid' => Money::of($total)->amount, 'orderId' => $orderId, 'reference' => $result['body']['receiptId'] ?? null];
    }

    /** payment_facility_unit_id of the booking's facility (or an ancestor): Sports Arena bookings are paid at Reception. */
    private function paymentFacility(Booking $b): ?string
    {
        $chain = app(PermissionChecker::class)->resolve(Scope::facility($b->facility_unit_id))[2];
        foreach ($chain as $facilityId) {
            $v = DB::table('operating_rule as r')->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')
                ->where('c.facility_unit_id', Ids::toBinary($facilityId))->where('r.rule_key', 'payment_facility_unit_id')->value('r.rule_value');
            if ($v !== null && Ids::isUuid($v)) {
                return Ids::normalize($v);
            }
        }

        return null;
    }

    private function createOrder(Booking $b, string $facility): string
    {
        $resource = $b->resource;
        if ($resource->product_id === null) {
            throw ApiProblem::unprocessable('validation_failed', 'This resource has no catalog product, so its booking cannot be paid through an order.');
        }
        $slots = max(1, (int) round($b->start_at->diffInMinutes($b->end_at) / max(1, $resource->slot_minutes)));
        $in = ['facilityId' => $facility, 'channel' => 'COUNTER', 'customerName' => $b->customer_name, 'lines' => [['productId' => $resource->product_id, 'quantity' => $slots * max(1, $b->quantity)]]];
        $created = app(OrderService::class)->create($in, hash('sha256', 'booking:'.$b->id));
        $orderId = $created['order']['id'];
        $orderTotal = DB::table('order')->where('id', Ids::toBinary($orderId))->value('total');
        if (Money::of($orderTotal)->compare(Money::of($b->total)) !== 0) {
            throw new ApiProblem(422, 'amount_mismatch', 'The catalog price of the slot-fee product does not match the booking total.', 'Amount mismatch', ['meta' => ['bookingTotal' => Money::of($b->total)->amount, 'orderTotal' => Money::of($orderTotal)->amount]]);
        }
        DB::table('booking')->where('id', Ids::toBinary($b->id))->update(['order_id' => Ids::toBinary($orderId)]);

        return $orderId;
    }
}
