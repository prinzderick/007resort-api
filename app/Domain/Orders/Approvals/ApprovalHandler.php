<?php

namespace App\Domain\Orders\Approvals;

/**
 * Applies (or discards) the action a pending approval protects. Called INSIDE the decision transaction, so the applied action,
 * its audit rows and the approval status change commit or roll back together. Other modules (inventory adjustments, refunds...)
 * may register handlers for their own action codes via ApprovalService::registerHandler().
 */
interface ApprovalHandler
{
    /** Apply the approved action. RequestContext actor = the approver. @param array<string, mixed> $payload */
    public function approved(object $approval, array $payload, string $approverStaffId): void;

    /** Requester's action was rejected / cancelled / expired: undo any provisional state. @param array<string, mixed> $payload */
    public function discarded(object $approval, array $payload, string $status): void;
}
