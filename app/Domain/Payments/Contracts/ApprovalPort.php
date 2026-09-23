<?php

namespace App\Domain\Payments\Contracts;

/**
 * Supervisor-approval workflow (architecture/06 §3, contract `/approvals`). Orders owns the workflow; Payments only
 * creates PENDING approvals for refunds / reversals and applies them when a supervisor approves
 * ({@see \App\Domain\Payments\Services\PaymentApprovalHandler}).
 */
interface ApprovalPort
{
    /**
     * Create a PENDING approval (or, when `$approvedBy` is given - supervisor step-up on the spot - an already APPROVED one so the
     * audit trail still carries an approval id). `$payload` is stored with the approval and handed back to the handler on approval.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> contract `Approval` shape
     */
    public function request(
        string $action,
        string $entityType,
        string $entityId,
        string $facilityId,
        ?string $amount,
        string $reason,
        string $requiredPermission,
        string $summary,
        array $payload,
        ?string $approvedBy = null,
    ): array;
}
