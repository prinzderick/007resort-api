<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Events\OrderSettled;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Entry points for the Payments module (call inside the payment's own transaction; everything here joins it).
 *
 *   applyPayment($orderId, '2500.0000')   record a captured amount against the order (over-payment is rejected)
 *   applyRefund($orderId, '500.0000')     reduce the paid amount (refund/reversal); a SETTLED order that is no longer fully paid reopens as SERVED
 *   markSettled($orderId)                 finish settlement: SERVED + fully paid => SETTLED (emits OrderSettled, frees the table)
 *   markTabSettled($tabId)                settle every order on the tab + close the tab
 *
 * Pay-first facilities: an order can be fully paid while still DRAFT/SENT; it becomes SETTLED when served.
 */
final class OrderSettlementService
{
    public function __construct(private readonly OrderService $orders, private readonly TableService $tables, private readonly Realtime $realtime) {}

    /** @return array<string, mixed> */
    public function applyPayment(string $orderId, string $amount): array
    {
        return DB::transaction(function () use ($orderId, $amount) {
            $o = $this->orders->lock($orderId);
            if (! Money::isValid($amount) || bccomp($amount, '0', 4) <= 0) {
                throw ApiProblem::unprocessable('validation_failed', 'The payment amount must be positive.');
            }
            if (in_array($o->status, ['VOIDED', 'PENDING_APPROVAL', 'SETTLED'], true)) {
                throw ApiProblem::conflict('order_state_invalid', "Cannot take payment for an order that is {$o->status}.");
            }
            $paid = bcadd($o->amount_paid, Money::normalize($amount), 4);
            if (bccomp($paid, $o->total, 4) > 0) {
                throw new ApiProblem(422, 'amount_mismatch', 'The payment exceeds the order balance.', 'Unprocessable entity', ['balanceDue' => Money::of($o->total)->sub(Money::of($o->amount_paid))->amount]);
            }
            DB::table('order')->where('id', $o->id)->update(['amount_paid' => $paid, 'row_version' => $o->row_version + 1]);
            $this->afterChange($orderId);

            return $this->finishIfComplete($orderId);
        });
    }

    /** @return array<string, mixed> */
    public function applyRefund(string $orderId, string $amount): array
    {
        return DB::transaction(function () use ($orderId, $amount) {
            $o = $this->orders->lock($orderId);
            $paid = bcsub($o->amount_paid, Money::normalize($amount), 4);
            if (bccomp($paid, '0', 4) < 0) {
                throw ApiProblem::unprocessable('amount_mismatch', 'The refund exceeds the amount paid.');
            }
            $upd = ['amount_paid' => $paid, 'row_version' => $o->row_version + 1];
            if ($o->status === 'SETTLED' && bccomp($paid, $o->total, 4) < 0) {
                $upd += ['status' => 'SERVED', 'settled_at' => null];
            }
            DB::table('order')->where('id', $o->id)->update($upd);
            Audit::record('order.refund.apply', 'Order', $orderId, old: ['amountPaid' => Fmt::money($o->amount_paid)], new: ['amountPaid' => $paid], facilityUnitId: Ids::fromBinary($o->facility_unit_id));
            $this->afterChange($orderId);

            return $this->orders->present($orderId);
        });
    }

    /** @return array<string, mixed> */
    public function markSettled(string $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            $o = $this->orders->lock($orderId);
            if ($o->status === 'SETTLED') {
                return $this->orders->present($orderId);
            }
            if (in_array($o->status, ['VOIDED', 'PENDING_APPROVAL'], true)) {
                throw ApiProblem::conflict('order_state_invalid', "An order that is {$o->status} cannot be settled.");
            }
            if (bccomp($o->amount_paid, $o->total, 4) < 0) {
                throw ApiProblem::conflict('balance_changed', 'The order is not fully paid.', ['balanceDue' => Money::of($o->total)->sub(Money::of($o->amount_paid))->amount]);
            }

            return $this->finishIfComplete($orderId);
        });
    }

    /** @return array<string, mixed> */
    public function markTabSettled(string $tabId): array
    {
        return DB::transaction(function () use ($tabId) {
            $tab = DB::table('tab')->where('id', Ids::toBinary($tabId))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Tab not found.');
            $orders = DB::table('order')->where('tab_id', $tab->id)->where('status', '!=', 'VOIDED')->orderBy('id')->get();
            foreach ($orders as $o) {
                $this->markSettled(Ids::fromBinary($o->id));
            }
            DB::table('tab')->where('id', $tab->id)->update(['status' => 'SETTLED', 'settled_at' => Fmt::now(), 'row_version' => $tab->row_version + 1]);
            Audit::record('tab.settle', 'Tab', $tabId, old: ['status' => $tab->status], new: ['status' => 'SETTLED'], facilityUnitId: Ids::fromBinary($tab->facility_unit_id));
            Outbox::record('TabSettled', 'Tab', $tabId, ['tabId' => $tabId, 'facilityId' => Ids::fromBinary($tab->facility_unit_id)], facilityId: Ids::fromBinary($tab->facility_unit_id));
            $this->tables->releaseIfIdle($tab->dining_table_id, 'NEEDS_CLEANING');

            return ['tabId' => $tabId, 'status' => 'SETTLED'];
        });
    }

    /** SERVED + fully paid (and total > 0 or zero-value) => SETTLED. */
    private function finishIfComplete(string $orderId): array
    {
        $o = $this->orders->find($orderId, true);
        if ($o->status === 'SERVED' && bccomp($o->amount_paid, $o->total, 4) >= 0) {
            $now = Fmt::now();
            DB::table('order')->where('id', $o->id)->update(['status' => 'SETTLED', 'settled_at' => $now, 'row_version' => $o->row_version + 1]);
            $fresh = $this->orders->find($orderId);
            $fid = Ids::fromBinary($fresh->facility_unit_id);
            Outbox::record('OrderSettled', 'Order', $orderId, $this->orders->outboxPayload($fresh), entityVersion: (int) $fresh->row_version, facilityId: $fid);
            event(new OrderSettled($orderId, $fid, Fmt::money($fresh->total), Fmt::money($fresh->amount_paid), Fmt::u($fresh->tab_id)));
            $this->tables->releaseIfIdle($fresh->dining_table_id, 'NEEDS_CLEANING');
            $this->realtime->orderUpdated($fresh, ['status', 'payment']);
        }

        return $this->orders->present($orderId);
    }

    private function afterChange(string $orderId): void
    {
        $fresh = $this->orders->find($orderId);
        Outbox::record('OrderUpdated', 'Order', $orderId, $this->orders->outboxPayload($fresh) + ['event' => 'payment'], entityVersion: (int) $fresh->row_version, facilityId: Ids::fromBinary($fresh->facility_unit_id));
        $this->realtime->orderUpdated($fresh, ['payment']);
    }
}
