<?php

namespace App\Domain\Payments\Services;

use App\Domain\Orders\Approvals\ApprovalHandler;
use App\Support\Ids;

/**
 * Applies / discards refund and reversal approvals. Registered with Orders' ApprovalService for the actions `payment.refund`
 * and `payment.reversal` (see PaymentsServiceProvider). Called INSIDE the decision transaction, so the refund row, the payment
 * state change, audit and the approval status change commit or roll back together. A rejected / cancelled approval needs no
 * Payments action: nothing was applied while it was PENDING.
 */
final class PaymentApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly RefundService $refunds) {}

    public function approved(object $approval, array $payload, string $approverStaffId): void
    {
        $this->refunds->applyApproved(
            Ids::fromBinary($approval->entity_id), $payload, Ids::fromBinary($approval->requested_by), $approverStaffId, Ids::fromBinary($approval->id),
        );
    }

    public function discarded(object $approval, array $payload, string $status): void {}
}
