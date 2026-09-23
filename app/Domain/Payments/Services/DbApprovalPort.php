<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\ApprovalPort;
use App\Domain\Payments\Support\Fmt;
use App\Domain\Payments\Support\Tenantless;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Default {@see ApprovalPort}: writes the shared `approval` table (columns added by Orders' `extend_approval` migration) so
 * Payments' refund / reversal requests show up in the same `GET /approvals` queue as order voids and discounts.
 * When the supervisor decides (Orders' `POST /approvals/{id}/decision`), Orders' approval service must hand APPROVED
 * approvals whose `action` starts with `payment.` to {@see PaymentApprovalHandler::apply()}.
 */
class DbApprovalPort implements ApprovalPort
{
    public function request(string $action, string $entityType, string $entityId, string $facilityId, ?string $amount, string $reason, string $requiredPermission, string $summary, array $payload, ?string $approvedBy = null): array
    {
        $id = Ids::uuid7();
        $requestedBy = RequestContext::staffId();
        ['org' => $org, 'site' => $site] = Tenantless::facility($facilityId);
        $now = Fmt::now();
        DB::table('approval')->insert([
            'id' => Ids::toBinary($id),
            'organization_id' => Ids::toBinary($org), 'site_id' => Ids::toBinary($site), 'facility_unit_id' => Ids::toBinary($facilityId),
            'action' => $action, 'entity_type' => $entityType, 'entity_id' => Ids::toBinary($entityId),
            'required_permission' => $requiredPermission, 'amount' => $amount, 'summary' => mb_substr($summary, 0, 255),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'requested_device_id' => Fmt::bin(RequestContext::deviceId()),
            'requested_by' => Ids::toBinary($requestedBy), 'approved_by' => Fmt::bin($approvedBy),
            'status' => $approvedBy !== null ? 'APPROVED' : 'PENDING', 'reason' => mb_substr($reason, 0, 255),
            'created_at' => $now, 'decided_at' => $approvedBy !== null ? $now : null,
        ]);

        return $this->present(DB::table('approval')->where('id', Ids::toBinary($id))->first());
    }

    /** @return array<string, mixed> contract `Approval` */
    public function present(object $a): array
    {
        $by = DB::table('staff')->where('id', $a->requested_by)->first(['first_name', 'last_name']);

        return [
            'id' => Ids::fromBinary($a->id),
            'action' => $a->action,
            'entityType' => $a->entity_type,
            'entityId' => Fmt::uuid($a->entity_id),
            'facilityId' => Fmt::uuid($a->facility_unit_id),
            'status' => $a->status,
            'requestedByStaffId' => Ids::fromBinary($a->requested_by),
            'requestedByName' => $by ? trim($by->first_name.' '.$by->last_name) : null,
            'requestedAt' => Fmt::iso($a->created_at),
            'reason' => $a->reason,
            'amount' => $a->amount === null ? null : Money::normalize((string) $a->amount),
            'summary' => $a->summary,
            'decidedByStaffId' => Fmt::uuid($a->approved_by),
            'decidedAt' => Fmt::iso($a->decided_at),
            'decisionNote' => $a->decision_note,
            'requiredPermission' => $a->required_permission,
        ];
    }
}
