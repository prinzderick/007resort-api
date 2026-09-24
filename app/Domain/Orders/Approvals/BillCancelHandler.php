<?php

namespace App\Domain\Orders\Approvals;

use App\Domain\Orders\Services\BillService;
use App\Support\Ids;

/** Applies an approved `bill.cancel` (reopen the order). Called inside the decision transaction. */
final class BillCancelHandler implements ApprovalHandler
{
    public function __construct(private readonly BillService $bills) {}

    public function approved(object $approval, array $payload, string $approverStaffId): void
    {
        $this->bills->applyCancel($payload['orderId'], (string) $payload['reason'], Ids::fromBinary($approval->id), $approverStaffId);
    }

    public function discarded(object $approval, array $payload, string $status): void {}
}
