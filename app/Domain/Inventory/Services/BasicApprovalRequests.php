<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\ApprovalRequests;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Minimal approval row against the V0001 `approval` table (requested_by, status PENDING, reason). */
class BasicApprovalRequests implements ApprovalRequests
{
    public function request(string $action, string $entityType, string $entityId, ?string $facilityUnitId, string $requiredPermission, string $requestedByStaffId, string $reason): string
    {
        $id = Ids::uuid7();
        DB::table('approval')->insert([
            'id' => Ids::toBinary($id),
            'requested_by' => Ids::toBinary($requestedByStaffId),
            'status' => 'PENDING',
            'reason' => mb_substr($action.': '.$reason, 0, 255),
        ]);

        return $id;
    }
}
