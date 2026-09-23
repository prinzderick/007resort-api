<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Events\PaymentCaptured;
use App\Support\Audit\Audit;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Everything that must happen, in the SAME database transaction, when money is captured or taken back:
 * tell Orders, write audit rows, write outbox events (ADR-0013), fire the in-process event.
 */
class PaymentEffects
{
    public function __construct(private readonly OrderPort $orders) {}

    /**
     * @param  array<string, array<string, mixed>>  $orders  locked order snapshots keyed by id
     * @param  array<string, string>  $paidBefore  order id => amount paid before this capture
     * @param  array<string, string>  $allocatedNow  order id => amount allocated by this capture
     * @return array<string, array{paid: string, settled: bool}>
     */
    public function syncOrders(array $orders, array $paidBefore, array $allocatedNow, string $groupId): array
    {
        $out = [];
        foreach ($allocatedNow as $orderId => $amount) {
            $paid = bcadd($paidBefore[$orderId], $amount, 4);
            $settled = bccomp($paid, $orders[$orderId]['total'], 4) >= 0;
            $this->orders->applyPayment($orderId, $paid, $settled, $groupId);
            $out[$orderId] = ['paid' => $paid, 'settled' => $settled];
        }

        return $out;
    }

    /**
     * Audit + outbox + event for a captured group. `$payments` = payment rows as arrays with keys id, tenderType, amount,
     * cashSessionId, receiptId, takenByStaffId, reference, tendered, changeGiven, allocations[{orderId, amount}].
     *
     * @param  list<array<string, mixed>>  $payments
     * @param  array{organizationId: string, siteId: string, facilityId: string, groupId: string, provider: string, staffId: ?string, subjectType?: ?string, subjectId?: ?string, capturedAt: string}  $ctx
     */
    public function captured(array $payments, array $ctx, string $actor = 'payment.capture'): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('PaymentEffects::captured must run inside the payment transaction.');
        }
        $orderIds = [];
        $total = '0.0000';
        foreach ($payments as $p) {
            $total = bcadd($total, $p['amount'], 4);
            foreach ($p['allocations'] as $a) {
                $orderIds[$a['orderId']] = true;
            }
            Audit::record(
                $actor, 'Payment', $p['id'],
                new: [
                    'amount' => $p['amount'], 'tenderType' => $p['tenderType'], 'provider' => $ctx['provider'], 'groupId' => $ctx['groupId'],
                    'reference' => $p['reference'], 'cashSessionId' => $p['cashSessionId'], 'receiptId' => $p['receiptId'],
                    'allocations' => $p['allocations'], 'changeGiven' => $p['changeGiven'],
                ],
                organizationId: $ctx['organizationId'], siteId: $ctx['siteId'], actorStaffId: $ctx['staffId'], facilityUnitId: $ctx['facilityId'],
            );
            // Online (provider-confirmed, possibly on the Cloud node) captures use the catalogue's OnlinePaymentConfirmed.
            Outbox::record(
                $ctx['provider'] === 'MANUAL' ? 'PaymentCompleted' : 'OnlinePaymentConfirmed', 'Payment', $p['id'],
                [
                    'paymentId' => $p['id'], 'groupId' => $ctx['groupId'], 'facilityId' => $ctx['facilityId'], 'tenderType' => $p['tenderType'],
                    'provider' => $ctx['provider'], 'amount' => $p['amount'], 'currency' => 'NGN', 'reference' => $p['reference'],
                    'orderRefs' => $p['allocations'], 'cashSessionId' => $p['cashSessionId'], 'receiptId' => $p['receiptId'],
                    'takenByStaffId' => $p['takenByStaffId'], 'capturedAt' => $ctx['capturedAt'],
                    'subjectType' => $ctx['subjectType'] ?? null, 'subjectId' => $ctx['subjectId'] ?? null,
                ],
                entityVersion: 1, organizationId: $ctx['organizationId'], siteId: $ctx['siteId'], facilityId: $ctx['facilityId'],
            );
        }

        event(new PaymentCaptured(
            paymentId: $payments[0]['id'],
            groupId: $ctx['groupId'],
            orderIds: array_keys($orderIds),
            amount: $total,
            tenders: array_map(fn ($p) => ['paymentId' => $p['id'], 'tenderType' => $p['tenderType'], 'amount' => $p['amount']], $payments),
            facilityId: $ctx['facilityId'],
            subjectType: $ctx['subjectType'] ?? null,
            subjectId: $ctx['subjectId'] ?? null,
            provider: $ctx['provider'],
        ));
    }
}
