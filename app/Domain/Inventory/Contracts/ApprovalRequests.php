<?php

namespace App\Domain\Inventory\Contracts;

use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\BasicApprovalRequests;

/**
 * Creates the supervisor-approval record for a gated inventory action. The default binding
 * ({@see BasicApprovalRequests}) inserts a minimal `approval` row. When the Orders
 * module's approval service is available it re-binds this contract (in its ServiceProvider) and decisions made
 * through `POST /approvals/{id}/decision` must call
 * {@see AdjustmentService::decide()} for `entityType = 'StockAdjustment'`.
 */
interface ApprovalRequests
{
    /** @return string approval id (UUID) */
    public function request(string $action, string $entityType, string $entityId, ?string $facilityUnitId, string $requiredPermission, string $requestedByStaffId, string $reason): string;
}
