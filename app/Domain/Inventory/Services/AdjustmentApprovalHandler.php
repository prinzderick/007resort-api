<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Orders\Approvals\ApprovalHandler;
use App\Support\Ids;

/** Plugs `inventory.adjustment` into the Orders ApprovalService: approve => post the ledger legs, otherwise close the request. */
final class AdjustmentApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly AdjustmentService $adjustments) {}

    public function approved(object $approval, array $payload, string $approverStaffId): void
    {
        $this->adjustments->applyApproved($payload['adjustmentId'], $approverStaffId, Ids::fromBinary($approval->id));
    }

    public function discarded(object $approval, array $payload, string $status): void
    {
        $this->adjustments->discard($payload['adjustmentId'], $status);
    }
}
