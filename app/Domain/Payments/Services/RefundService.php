<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Orders\Approvals\ApprovalService;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Support\FacilityRules;
use App\Domain\Payments\Support\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Refunds and same-session reversals. Both are NEW immutable rows referencing the payment (architecture/07 §2); the
 * payment header only moves along its state machine (PARTIALLY_REFUNDED / REFUNDED / REVERSED).
 *
 * Over-refund protection has three layers: (1) the payment row is locked FOR UPDATE and the running total is re-read from
 * that locked row, (2) `ck_pay_refund_cap` CHECK (refunded_amount <= amount), (3) the BEFORE UPDATE trigger only lets
 * refunded_amount grow and enforces REFUNDED <=> refunded = amount.
 *
 * Approval (architecture/06 §3): the caller needs `refund.execute` / `payment.reversal.execute`. The action is applied
 * immediately when the caller holds the matching `.approve` permission, or presents an `X-Step-Up-Token` for a supervisor
 * who does, or neither the grant (`requires_approval`) nor the facility rule demands approval. Otherwise a PENDING approval
 * is created (HTTP 202) and Payments applies the action when a supervisor approves ({@see PaymentApprovalHandler}).
 */
class RefundService
{
    public const REFUND = 'payment.refund';

    public const REVERSAL = 'payment.reversal';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ApprovalService $approvals,
        private readonly FacilityRules $rules,
        private readonly OrderPort $orders,
        private readonly PaymentEffects $effects,
    ) {}

    /**
     * @param  array{amount: string, reason: string, tenderType?: ?string}  $in
     * @return array{status: int, body: array<string, mixed>}
     */
    public function refund(string $paymentId, array $in, string $staffId): array
    {
        return DB::transaction(function () use ($paymentId, $in, $staffId) {
            $p = $this->lockPayment($paymentId);
            $facilityId = Ids::fromBinary($p->facility_unit_id);
            $decision = $this->decision(self::REFUND, 'refund.execute', 'refund.approve', $staffId, $facilityId, $paymentId, Money::normalize($in['amount']));

            $amount = Money::normalize($in['amount']);
            $this->assertRefundable($p, $amount);
            if (isset($in['tenderType']) && $in['tenderType'] !== null && $in['tenderType'] !== $p->tender_type) {
                throw ApiProblem::unprocessable('validation_failed', 'A refund is paid out through the tender the money came in on.', ['tenderType' => ['Must be '.$p->tender_type.'.']]);
            }
            $reservedId = Ids::uuid7();

            if ($decision['mode'] === 'PENDING') {
                $approval = $this->requestApproval(
                    self::REFUND, 'refund.approve', $paymentId, $facilityId, $amount, $in['reason'],
                    "Refund {$amount} of {$p->tender_type} payment", ['kind' => 'REFUND', 'amount' => $amount, 'reason' => $in['reason'], 'reservedId' => $reservedId],
                );

                return ['status' => 202, 'body' => [
                    'id' => $reservedId, 'paymentId' => $paymentId, 'amount' => $amount, 'reason' => $in['reason'],
                    'status' => 'PENDING_APPROVAL', 'approvalId' => $approval['id'], 'createdAt' => $approval['requestedAt'],
                    'approval' => $approval,
                ]];
            }

            $refund = $this->applyRefund($p, $amount, $in['reason'], $staffId, $decision['approverId'] ?? $staffId, null, $reservedId, $decision['approverId']);

            return ['status' => 201, 'body' => $refund];
        });
    }

    /**
     * @param  array{reason: string}  $in
     * @return array{status: int, body: array<string, mixed>}
     */
    public function reverse(string $paymentId, array $in, string $staffId): array
    {
        return DB::transaction(function () use ($paymentId, $in, $staffId) {
            $p = $this->lockPayment($paymentId);
            $facilityId = Ids::fromBinary($p->facility_unit_id);
            $decision = $this->decision(self::REVERSAL, 'payment.reversal.execute', 'payment.reversal.approve', $staffId, $facilityId, $paymentId, Money::normalize((string) $p->amount));
            $this->assertReversible($p);
            $reservedId = Ids::uuid7();

            if ($decision['mode'] === 'PENDING') {
                $this->assertSessionOpen($p, false);
                $amount = Money::normalize((string) $p->amount);
                $approval = $this->requestApproval(
                    self::REVERSAL, 'payment.reversal.approve', $paymentId, $facilityId, $amount, $in['reason'],
                    "Reverse {$p->tender_type} payment of {$amount}", ['kind' => 'REVERSAL', 'reason' => $in['reason'], 'reservedId' => $reservedId],
                );

                return ['status' => 202, 'body' => [
                    'id' => $reservedId, 'paymentId' => $paymentId, 'reason' => $in['reason'], 'status' => 'PENDING_APPROVAL',
                    'approvalId' => $approval['id'], 'createdAt' => $approval['requestedAt'], 'approval' => $approval,
                ]];
            }

            return ['status' => 201, 'body' => $this->applyReversal($p, $in['reason'], $staffId, $decision['approverId'] ?? $staffId, null, $reservedId, $decision['approverId'])];
        });
    }

    /**
     * Apply a supervisor-APPROVED request (called by {@see PaymentApprovalHandler}). Re-validates everything: the payment
     * may have changed while the approval sat in the queue.
     *
     * @param  array<string, mixed>  $payload  approval.payload
     * @return array<string, mixed>
     */
    public function applyApproved(string $paymentId, array $payload, string $requestedBy, string $approverId, string $approvalId): array
    {
        return DB::transaction(function () use ($paymentId, $payload, $requestedBy, $approverId, $approvalId) {
            $p = $this->lockPayment($paymentId);
            if (($payload['kind'] ?? null) === 'REVERSAL') {
                $this->assertReversible($p);

                return $this->applyReversal($p, (string) $payload['reason'], $requestedBy, $approverId, $approvalId, $payload['reservedId'], $approverId);
            }
            $amount = Money::normalize((string) $payload['amount']);
            $this->assertRefundable($p, $amount);

            return $this->applyRefund($p, $amount, (string) $payload['reason'], $requestedBy, $approverId, $approvalId, $payload['reservedId'], $approverId);
        });
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function lockPayment(string $paymentId): object
    {
        return DB::selectOne('SELECT * FROM payment WHERE id = ? FOR UPDATE', [Ids::toBinary($paymentId)])
            ?? throw ApiProblem::notFound('not_found', 'Payment not found.');
    }

    private function assertRefundable(object $p, string $amount): void
    {
        if (! in_array($p->status, ['CAPTURED', 'PARTIALLY_REFUNDED'], true)) {
            throw ApiProblem::conflict('payment_state_invalid', "A payment in status {$p->status} cannot be refunded.", ['status' => $p->status]);
        }
        if (bccomp($amount, '0', 4) <= 0) {
            throw ApiProblem::unprocessable('validation_failed', 'The refund amount must be greater than zero.', ['amount' => ['Must be greater than zero.']]);
        }
        $refundable = bcsub(Money::normalize((string) $p->amount), Money::normalize((string) $p->refunded_amount), 4);
        if (bccomp($amount, $refundable, 4) > 0) {
            throw ApiProblem::unprocessable('amount_mismatch', "Cannot refund {$amount}: only {$refundable} of this payment is still refundable.", []);
        }
    }

    /** Payment-state part of the reversal rules (no cash-session lock; see assertSessionOpen). */
    private function assertReversible(object $p): void
    {
        if ($p->status !== 'CAPTURED') {
            throw ApiProblem::conflict('payment_state_invalid', "A payment in status {$p->status} cannot be reversed (use a refund for a partially refunded payment).", ['status' => $p->status]);
        }
        if ($p->cash_session_id === null) {
            $age = now('UTC')->diffInHours(CarbonImmutable::parse((string) $p->created_at, 'UTC'), true);
            if ($age > (int) config('payments.reversal_window_hours', 24)) {
                throw ApiProblem::conflict('payment_state_invalid', 'The reversal window for this payment has passed: use a refund instead.');
            }
        }
    }

    /**
     * A reversal is a same-session correction: the payment's cash session must still be OPEN. `$lock` takes the shared lock
     * (authoritative - a concurrent close then waits for us); without it the check is an early, advisory read.
     */
    private function assertSessionOpen(object $p, bool $lock): void
    {
        if ($p->cash_session_id === null) {
            return;
        }
        $sql = 'SELECT status FROM cash_session WHERE id = ?'.($lock ? ' FOR SHARE' : '');
        if (DB::selectOne($sql, [$p->cash_session_id])->status !== 'OPEN') {
            throw ApiProblem::conflict('payment_state_invalid', 'Its cash session is closed: a reversal is a same-session correction, use a refund instead.');
        }
    }

    /**
     * Orders' approval gate decides EXECUTE (caller holds the `.approve` permission, or presents a valid X-Step-Up-Token from a
     * supervisor who does, or no approval is needed) versus REQUEST (PENDING approval, HTTP 202) versus 403.
     * "No approval needed" = neither the caller's grant is flagged `requires_approval` nor the facility rule demands it.
     *
     * @return array{mode: 'APPLY'|'PENDING', approverId: ?string}
     */
    private function decision(string $action, string $executePerm, string $approvePerm, string $staffId, string $facilityId, string $paymentId, string $amount): array
    {
        $grant = $this->permissions->grant($staffId, $executePerm, Scope::facility($facilityId));
        $notNeeded = $grant !== null && ! $grant->requiresApproval && ! $this->rules->approvalRequired($facilityId, $action, $amount);
        $gate = $this->approvals->gate($executePerm, $approvePerm, $facilityId, null, $paymentId, $notNeeded);

        return ['mode' => $gate['mode'] === 'EXECUTE' ? 'APPLY' : 'PENDING', 'approverId' => $gate['approvedBy']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> contract `Approval`
     */
    private function requestApproval(string $action, string $approvePerm, string $paymentId, string $facilityId, string $amount, string $reason, string $summary, array $payload): array
    {
        $row = $this->approvals->request($action, 'Payment', $paymentId, $facilityId, $approvePerm, $reason, $payload, $amount, $summary);

        return $this->approvals->present($row);
    }

    /** @return array<string, mixed> */
    private function applyRefund(object $p, string $amount, string $reason, string $requestedBy, string $executedBy, ?string $approvalId, string $refundId, ?string $approverId): array
    {
        $paymentId = Ids::fromBinary($p->id);
        $facilityId = Ids::fromBinary($p->facility_unit_id);
        $now = Fmt::now();

        $sessionBin = null;
        if ($p->tender_type === 'CASH') { // cash leaves the drawer of the cashier paying it out
            $s = DB::selectOne("SELECT id FROM cash_session WHERE staff_id = ? AND status = 'OPEN' AND facility_unit_id = ? FOR SHARE", [Ids::toBinary($requestedBy), $p->facility_unit_id]);
            if ($s === null && $this->rules->requireCashSession($facilityId)) {
                throw ApiProblem::conflict('cash_session_required', 'Open a cash session before paying out a cash refund.');
            }
            $sessionBin = $s?->id;
        }

        $newRefunded = bcadd(Money::normalize((string) $p->refunded_amount), $amount, 4);
        $full = bccomp($newRefunded, Money::normalize((string) $p->amount), 4) === 0;
        $newStatus = $full ? 'REFUNDED' : 'PARTIALLY_REFUNDED';

        DB::table('refund')->insert([
            'id' => Ids::toBinary($refundId), 'organization_id' => $p->organization_id, 'site_id' => $p->site_id, 'payment_id' => $p->id,
            'amount' => $amount, 'reason' => mb_substr($reason, 0, 255), 'tender_type' => $p->tender_type, 'cash_session_id' => $sessionBin,
            'approval_id' => Fmt::bin($approvalId), 'requested_by_staff_id' => Ids::toBinary($requestedBy), 'executed_by_staff_id' => Ids::toBinary($executedBy),
            'provider_action_required' => $p->provider === 'MANUAL' ? 0 : 1, 'created_at' => $now,
        ]);
        DB::table('payment')->where('id', $p->id)->update(['refunded_amount' => $newRefunded, 'status' => $newStatus, 'row_version' => DB::raw('row_version + 1')]);

        Audit::record('payment.refund', 'Payment', $paymentId,
            old: ['status' => $p->status, 'refundedAmount' => Money::normalize((string) $p->refunded_amount)],
            new: ['status' => $newStatus, 'refundedAmount' => $newRefunded, 'refundId' => $refundId, 'amount' => $amount, 'reason' => $reason, 'approverId' => $approverId],
            organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), actorStaffId: $executedBy, facilityUnitId: $facilityId, approvalId: $approvalId);
        Outbox::record('PaymentReversed', 'Payment', $paymentId, [
            'kind' => 'REFUND', 'paymentId' => $paymentId, 'refundId' => $refundId, 'amount' => $amount, 'currency' => 'NGN', 'reason' => $reason,
            'paymentStatus' => $newStatus, 'facilityId' => $facilityId, 'providerActionRequired' => $p->provider !== 'MANUAL', 'createdAt' => Fmt::iso($now),
        ], entityVersion: (int) $p->row_version + 1, organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), facilityId: $facilityId);

        return [
            'id' => $refundId, 'paymentId' => $paymentId, 'amount' => $amount, 'reason' => $reason, 'status' => 'COMPLETED',
            'approvalId' => $approvalId, 'createdAt' => Fmt::iso($now), 'tenderType' => $p->tender_type,
            'providerActionRequired' => $p->provider !== 'MANUAL',
        ];
    }

    /** @return array<string, mixed> */
    private function applyReversal(object $p, string $reason, string $requestedBy, string $executedBy, ?string $approvalId, string $reversalId, ?string $approverId): array
    {
        $paymentId = Ids::fromBinary($p->id);
        $facilityId = Ids::fromBinary($p->facility_unit_id);
        $now = Fmt::now();

        // Lock order: payment -> orders -> cash session (same relative order as a settlement: orders -> session).
        $allocs = DB::table('payment_allocation')->where('payment_id', $p->id)->sharedLock()->get(['order_id', 'amount']);
        $locked = $this->orders->lockOrders($allocs->map(fn ($a) => Ids::fromBinary($a->order_id))->all());
        $this->assertSessionOpen($p, true);

        DB::table('reversal')->insert([
            'id' => Ids::toBinary($reversalId), 'organization_id' => $p->organization_id, 'site_id' => $p->site_id, 'payment_id' => $p->id,
            'amount' => Money::normalize((string) $p->amount), 'reason' => mb_substr($reason, 0, 255), 'tender_type' => $p->tender_type,
            'cash_session_id' => $p->cash_session_id, 'approval_id' => Fmt::bin($approvalId),
            'requested_by_staff_id' => Ids::toBinary($requestedBy), 'executed_by_staff_id' => Ids::toBinary($executedBy), 'created_at' => $now,
        ]);
        DB::table('payment')->where('id', $p->id)->update(['status' => 'REVERSED', 'row_version' => DB::raw('row_version + 1')]);

        foreach ($allocs as $a) { // give the money back to the orders (Orders re-opens a SETTLED order as SERVED)
            $this->orders->applyReversal(Ids::fromBinary($a->order_id), Money::normalize((string) $a->amount), Ids::fromBinary($p->group_id));
        }

        Audit::record('payment.reverse', 'Payment', $paymentId,
            old: ['status' => 'CAPTURED'], new: ['status' => 'REVERSED', 'reversalId' => $reversalId, 'reason' => $reason, 'amount' => Money::normalize((string) $p->amount), 'approverId' => $approverId],
            organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), actorStaffId: $executedBy, facilityUnitId: $facilityId, approvalId: $approvalId);
        Outbox::record('PaymentReversed', 'Payment', $paymentId, [
            'kind' => 'REVERSAL', 'paymentId' => $paymentId, 'reversalId' => $reversalId, 'amount' => Money::normalize((string) $p->amount), 'currency' => 'NGN',
            'reason' => $reason, 'paymentStatus' => 'REVERSED', 'facilityId' => $facilityId, 'orderIds' => array_keys($locked), 'createdAt' => Fmt::iso($now),
        ], entityVersion: (int) $p->row_version + 1, organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), facilityId: $facilityId);

        return ['id' => $reversalId, 'paymentId' => $paymentId, 'reason' => $reason, 'status' => 'COMPLETED', 'approvalId' => $approvalId, 'createdAt' => Fmt::iso($now)];
    }
}
