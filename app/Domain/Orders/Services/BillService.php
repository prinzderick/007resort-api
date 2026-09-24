<?php

namespace App\Domain\Orders\Services;

use App\Domain\Identity\Services\StepUpService;
use App\Domain\Orders\Approvals\ApprovalService;
use App\Domain\Orders\Broadcast\BillPrinted;
use App\Domain\Orders\Support\PreBillRenderer;
use App\Domain\Organization\Services\TaxSettingService;
use App\Support\Api\Authz;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Pre-bill (docs/WAITER_COLLECTION.md section 1). Printing FREEZES the order (`bill_printed_at`); the order `status` is not touched.
 * Lock order: the order row first (like every Orders mutation). Reprints are counted and audited; reopening a bill needs the
 * approve permission (or an approval) and, when the facility rule says so, makes the NEXT print supervisor-only.
 */
final class BillService
{
    public const CANCEL_ACTION = 'bill.cancel';

    private const PRINTABLE = ['SENT', 'IN_PREPARATION', 'READY', 'SERVED'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly Presenter $presenter,
        private readonly Realtime $realtime,
        private readonly OperatingRules $rules,
        private readonly ApprovalService $approvals,
        private readonly TaxSettingService $tax,
    ) {}

    /** @return array{order: array<string, mixed>, bill: array<string, mixed>, reprint: bool} */
    public function print(string $orderId, ?string $note): array
    {
        $pre = $this->orders->find($orderId);
        $facilityId = Ids::fromBinary($pre->facility_unit_id);
        Authz::require('bill.print', $facilityId);

        return DB::transaction(function () use ($orderId, $facilityId, $note) {
            $o = $this->orders->lock($orderId);
            if ($o->status === 'PENDING_APPROVAL') {
                throw ApiProblem::conflict('approval_pending', 'This order has a pending approval.', ['approvalId' => Fmt::u($o->pending_approval_id)]);
            }
            $payFirst = $this->rules->forFacility($facilityId)['paymentTiming'] === OperatingRules::PAY_FIRST;
            if (! in_array($o->status, self::PRINTABLE, true) && ! ($payFirst && $o->status === 'DRAFT')) {
                throw ApiProblem::conflict('order_state_invalid', "A bill cannot be printed for an order that is {$o->status}.", ['status' => $o->status]);
            }
            $liveLines = (int) DB::table('order_line')->where('order_id', $o->id)->whereNotIn('status', ['REMOVED', 'VOIDED'])->count();
            if ($liveLines === 0 || bccomp($o->total, '0', 4) <= 0) {
                throw ApiProblem::conflict('order_state_invalid', 'There is nothing to bill on this order.');
            }
            $reprint = $o->bill_printed_at !== null;
            $staff = Authz::staffId();
            $raw = $this->rules->forFacility($facilityId)['raw'];
            if (! $reprint && (int) $o->bill_reopen_count > 0 && self::truthy($raw['pre_bill_requires_supervisor_if_reopened'] ?? 'true')) {
                if (! Authz::can('bill.cancel.approve', $facilityId)) {
                    $approver = app(StepUpService::class)->consume(request(), 'bill.cancel.approve', null, $orderId);
                    if ($approver === null || ! Authz::can('bill.cancel.approve', $facilityId, $approver)) {
                        throw ApiProblem::forbidden('supervisor_required', 'This bill was cancelled before; a supervisor must authorise printing it again.');
                    }
                }
            }
            $now = Fmt::now();
            $count = (int) $o->bill_print_count + 1;
            DB::table('order')->where('id', $o->id)->update([
                'bill_printed_at' => $reprint ? $o->bill_printed_at : $now,
                'bill_printed_by' => $reprint ? $o->bill_printed_by : Ids::toBinary($staff),
                'bill_printed_device_id' => $reprint ? $o->bill_printed_device_id : Fmt::b(RequestContext::deviceId()),
                'bill_print_count' => $count,
                'row_version' => $o->row_version + 1,
            ]);
            $fresh = $this->orders->find($orderId);
            Audit::record($reprint ? 'order.bill.reprint' : 'order.bill.print', 'Order', $orderId,
                old: ['billPrintCount' => (int) $o->bill_print_count], new: ['billPrintCount' => $count, 'total' => Fmt::money($fresh->total), 'note' => $note], facilityUnitId: $facilityId);
            Outbox::record('OrderUpdated', 'Order', $orderId, $this->orders->outboxPayload($fresh) + ['event' => 'bill.printed', 'billPrintCount' => $count],
                entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
            $bill = $this->preBill($fresh, $reprint, $now, $staff, $raw);
            $this->realtime->orderUpdated($fresh, ['status', 'bill']);
            $channels = [$this->realtime->facilityOrders($facilityId)];
            if (($device = RequestContext::deviceId()) !== null) {
                $channels[] = 'device.'.$device;
            }
            event(new BillPrinted($channels, ['order' => $this->presenter->summary($fresh), 'printCount' => $count, 'reprint' => $reprint]));

            return ['order' => $this->presenter->order($fresh), 'bill' => $bill, 'reprint' => $reprint];
        });
    }

    /** @return array{done: bool, order: array<string, mixed>, approval: ?array<string, mixed>} */
    public function cancel(string $orderId, string $reason, ?string $stepUpToken): array
    {
        $pre = $this->orders->find($orderId);
        $facilityId = Ids::fromBinary($pre->facility_unit_id);

        return DB::transaction(function () use ($orderId, $reason, $stepUpToken, $facilityId) {
            $o = $this->orders->lock($orderId);
            $this->assertCancellable($o);
            $gate = $this->approvals->gate('bill.cancel.execute', 'bill.cancel.approve', $facilityId, $stepUpToken, $orderId);
            if ($gate['mode'] === 'EXECUTE') {
                $this->applyCancel($orderId, $reason, null, $gate['approvedBy']);

                return ['done' => true, 'order' => $this->orders->present($orderId), 'approval' => null];
            }
            $approval = $this->approvals->request(self::CANCEL_ACTION, 'Order', $orderId, $facilityId, 'bill.cancel.approve', $reason,
                ['orderId' => $orderId, 'reason' => $reason], Fmt::money($o->total), "Cancel printed bill of order {$o->order_number}");

            return ['done' => false, 'order' => $this->orders->present($orderId), 'approval' => $this->approvals->present($approval)];
        });
    }

    /** Applies the cancel. Caller holds the transaction; re-locks the order (approval flow). */
    public function applyCancel(string $orderId, string $reason, ?string $approvalId, ?string $approvedBy): void
    {
        $o = $this->orders->lock($orderId);
        $this->assertCancellable($o);
        $facilityId = Ids::fromBinary($o->facility_unit_id);
        DB::table('order')->where('id', $o->id)->update([
            'bill_printed_at' => null, 'bill_printed_by' => null, 'bill_printed_device_id' => null,
            'bill_reopen_count' => $o->bill_reopen_count + 1, 'row_version' => $o->row_version + 1,
        ]);
        $fresh = $this->orders->find($orderId);
        Audit::record('order.bill.cancel', 'Order', $orderId, old: ['billState' => 'BILL_PRINTED'], new: ['billState' => 'OPEN', 'reason' => $reason, 'approvedBy' => $approvedBy],
            facilityUnitId: $facilityId, approvalId: $approvalId);
        Outbox::record('OrderUpdated', 'Order', $orderId, $this->orders->outboxPayload($fresh) + ['event' => 'bill.cancelled', 'reason' => $reason], entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
        $this->realtime->orderUpdated($fresh, ['bill']);
    }

    private function assertCancellable(object $o): void
    {
        if ($o->bill_printed_at === null) {
            throw ApiProblem::conflict('bill_not_printed', 'No bill has been printed for this order.');
        }
        $blocked = DB::table('payment_allocation as pa')->join('payment as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.order_id', $o->id)->whereIn('p.status', ['PENDING_CONFIRMATION', 'AUTHORIZING', 'CAPTURED', 'PARTIALLY_REFUNDED', 'REFUNDED'])->exists();
        if ($blocked) {
            throw ApiProblem::conflict('collections_pending', 'Money has been collected or paid against this bill; resolve it (confirm, reject, cancel or refund) before cancelling the bill.');
        }
    }

    /**
     * @param  array<string, string>  $raw  facility operating rules
     * @return array<string, mixed>
     */
    private function preBill(object $o, bool $reprint, string $printedAt, string $printer, array $raw): array
    {
        $facility = DB::table('facility_unit')->where('id', $o->facility_unit_id)->first(['id', 'name', 'organization_id']);
        $org = DB::table('organization')->where('id', $facility->organization_id)->value('name');
        $waiter = DB::table('staff')->where('id', $o->created_by)->first(['id', 'first_name', 'last_name']);
        $table = $o->dining_table_id ? DB::table('dining_table')->where('id', $o->dining_table_id)->value('label') : null;
        $lines = DB::table('order_line')->where('order_id', $o->id)->whereNotIn('status', ['REMOVED', 'VOIDED'])->orderBy('line_no')->get(['product_name', 'quantity', 'unit_price', 'line_total'])
            ->map(fn ($l) => ['name' => $l->product_name, 'quantity' => (int) $l->quantity, 'unitPrice' => Fmt::money($l->unit_price), 'lineTotal' => Fmt::money($l->line_total)])->all();
        $vat = $this->tax->get(Ids::fromBinary($facility->organization_id));
        $vatOn = $vat['vatEnabled'] && $vat['vatNumber'] !== null && bccomp($o->tax_total, '0', 4) > 0;
        $taxLines = $vatOn ? [['label' => 'VAT ('.rtrim(rtrim((string) $vat['vatRatePercent'], '0'), '.').'%)', 'amount' => Fmt::money($o->tax_total)]] : [];
        $payEnabled = self::truthy($raw['bill_pay_link_enabled'] ?? 'false');
        $base = (string) config('payments.pay_link_base_url', '');
        $url = $payEnabled && $base !== '' ? rtrim($base, '/').'/pay/'.rawurlencode($o->order_number) : null;

        $b = [
            'kind' => 'PRE_BILL',
            'title' => 'BILL - NOT A RECEIPT',
            'orderId' => Ids::fromBinary($o->id),
            'orderNumber' => $o->order_number,
            'businessName' => (string) (config('payments.receipt.business_name') ?: $org),
            'facility' => ['id' => Ids::fromBinary($facility->id), 'name' => $facility->name],
            'tableLabel' => $table,
            'waiter' => ['id' => Ids::fromBinary($waiter->id), 'name' => trim($waiter->first_name.' '.$waiter->last_name)],
            'printedAt' => Fmt::ts($printedAt),
            'printCount' => (int) $o->bill_print_count,
            'reprint' => $reprint,
            'lines' => $lines,
            'subtotal' => Fmt::money($o->subtotal),
            'discountTotal' => Fmt::money($o->discount_total),
            'taxLines' => $taxLines,
            'taxTotal' => $vatOn ? Fmt::money($o->tax_total) : '0.0000',
            'total' => Fmt::money($o->total),
            'amountPaid' => Fmt::money($o->amount_paid),
            'balanceDue' => Money::of($o->total)->sub(Money::of($o->amount_paid))->amount,
            'currency' => $o->currency,
            'payLink' => ['enabled' => $payEnabled, 'reference' => $payEnabled ? $o->order_number : null, 'url' => $url, 'qrPayload' => $url],
            'disclaimer' => 'This is not a receipt. Pay only through the waiter\'s card machine, the transfer link or the cashier.',
        ];
        $b['printLines'] = PreBillRenderer::lines($b, (int) config('payments.receipt.columns', 48));

        return $b;
    }

    public static function truthy(mixed $v): bool
    {
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
