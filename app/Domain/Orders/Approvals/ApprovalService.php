<?php

namespace App\Domain\Orders\Approvals;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Identity\Services\StepUpService;
use App\Domain\Orders\Broadcast\ApprovalDecided;
use App\Domain\Orders\Broadcast\ApprovalRequested;
use App\Support\Api\Authz;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sensitive-action approvals (architecture/06, 17 §5; contract Approval schema).
 *
 *  gate():     decides EXECUTE now (caller holds the approve permission, or presents a valid X-Step-Up-Token, or no approval
 *              is needed) versus REQUEST (create an approval, HTTP 202) versus 403 (caller may not even request).
 *  request():  inserts the `approval` row (+ audit).
 *  decide():   a supervisor with the approval's required permission (never the requester) approves/rejects; approving applies the
 *              action through the registered handler in the SAME transaction and writes the audit rows.
 */
final class ApprovalService
{
    public const TTL_HOURS = 12;

    /** @var array<string, ApprovalHandler> */
    private array $handlers = [];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function registerHandler(string $action, ApprovalHandler $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    /**
     * @return array{mode: 'EXECUTE'|'REQUEST', approvedBy: ?string, stepUp: bool}
     */
    public function gate(string $executePermission, string $approvePermission, string $facilityId, ?string $stepUpToken, ?string $entityId, bool $approvalNotNeeded = false): array
    {
        $staff = Authz::staffId();
        $canApprove = Authz::can($approvePermission, $facilityId);
        if (! $canApprove && ! Authz::can($executePermission, $facilityId)) {
            throw ApiProblem::permissionDenied($executePermission);
        }
        if ($canApprove) {
            return ['mode' => 'EXECUTE', 'approvedBy' => $staff, 'stepUp' => false];
        }
        // inline authorisation: a supervisor authenticated on this device via POST /auth/staff/step-up (single-use token)
        $approver = app(StepUpService::class)->consume(request(), $approvePermission, null, $entityId);
        if ($approver !== null) {
            if (! Authz::can($approvePermission, $facilityId, $approver)) {
                throw ApiProblem::forbidden('step_up_required', 'The step-up approver does not hold the approve permission here.');
            }

            return ['mode' => 'EXECUTE', 'approvedBy' => $approver, 'stepUp' => true];
        }
        if ($approvalNotNeeded) {
            return ['mode' => 'EXECUTE', 'approvedBy' => null, 'stepUp' => false];
        }

        return ['mode' => 'REQUEST', 'approvedBy' => null, 'stepUp' => false];
    }

    /**
     * @param  array<string, mixed>  $payload  everything the handler needs to apply the action later
     * @return object the approval row
     */
    public function request(string $action, string $entityType, string $entityId, string $facilityId, string $requiredPermission, string $reason, array $payload, ?string $amount, string $summary): object
    {
        $id = Ids::uuid7();
        DB::table('approval')->insert([
            'id' => Ids::toBinary($id),
            'organization_id' => Ids::toBinary(Tenant::organizationId()),
            'site_id' => Fmt::b(Tenant::siteId()),
            'facility_unit_id' => Ids::toBinary($facilityId),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => Ids::toBinary($entityId),
            'required_permission' => $requiredPermission,
            'amount' => $amount,
            'summary' => mb_substr($summary, 0, 255),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'requested_by' => Ids::toBinary(Authz::staffId()),
            'requested_device_id' => Fmt::b(RequestContext::deviceId()),
            'status' => 'PENDING',
            'reason' => mb_substr($reason, 0, 255),
            'expires_at' => now('UTC')->addHours(self::TTL_HOURS)->format('Y-m-d H:i:s.u'),
        ]);
        Audit::record($action.'.request', 'Approval', $id, new: ['action' => $action, 'entityType' => $entityType, 'entityId' => $entityId, 'reason' => $reason, 'amount' => $amount], facilityUnitId: $facilityId, approvalId: $id);
        $row = DB::table('approval')->where('id', Ids::toBinary($id))->first();
        $this->broadcastRequested($row, $facilityId);

        return $row;
    }

    /** @return array<string, mixed> Approval (contract) */
    public function decide(string $approvalId, string $decision, ?string $note, ?string $stepUpToken = null): array
    {
        return DB::transaction(function () use ($approvalId, $decision, $note, $stepUpToken) {
            $a = DB::table('approval')->where('id', Ids::toBinary($approvalId))->lockForUpdate()->first();
            if (! $a || $a->action === null) {
                throw ApiProblem::notFound('not_found', 'Approval not found.');
            }
            $fid = Ids::fromBinary($a->facility_unit_id);
            $decider = Authz::staffId();
            if ($a->status !== 'PENDING') {
                throw ApiProblem::conflict('approval_already_decided', 'This approval has already been '.strtolower($a->status).'.', ['status' => $a->status]);
            }
            if ($a->expires_at !== null && $a->expires_at < Fmt::now()) {
                throw ApiProblem::conflict('approval_expired', 'This approval request has expired. Ask for it again.');
            }
            if (! Authz::can($a->required_permission, $fid)) {
                if ($stepUpToken !== null && $stepUpToken !== '') {
                    request()->headers->set('X-Step-Up-Token', $stepUpToken);
                }
                $decider = app(StepUpService::class)->consume(request(), $a->required_permission, null, Ids::fromBinary($a->entity_id));
                if ($decider === null || ! Authz::can($a->required_permission, $fid, $decider)) {
                    throw ApiProblem::permissionDenied($a->required_permission);
                }
            }
            if (Ids::fromBinary($a->requested_by) === $decider) {
                throw ApiProblem::forbidden('permission_denied', 'You cannot decide your own approval request.');
            }
            $payload = json_decode((string) $a->payload, true) ?: [];
            $handler = $this->handlers[$a->action] ?? throw ApiProblem::conflict('approval_unsupported', "No handler for {$a->action}.");

            if ($decision === 'APPROVE') {
                $handler->approved($a, $payload, $decider);
                $status = 'APPROVED';
            } else {
                $handler->discarded($a, $payload, 'REJECTED');
                $status = 'REJECTED';
            }
            DB::table('approval')->where('id', $a->id)->update([
                'status' => $status, 'approved_by' => Ids::toBinary($decider), 'decided_at' => Fmt::now(),
                'decision_note' => $note !== null ? mb_substr($note, 0, 500) : null, 'row_version' => $a->row_version + 1,
            ]);
            Audit::record($a->action.'.'.($status === 'APPROVED' ? 'approve' : 'reject'), 'Approval', $approvalId,
                old: ['status' => 'PENDING'], new: ['status' => $status, 'note' => $note, 'decidedBy' => $decider], facilityUnitId: $fid, approvalId: $approvalId);

            $row = DB::table('approval')->where('id', $a->id)->first();
            $this->broadcastDecided($row, $status === 'APPROVED', $payload);

            return $this->present($row);
        });
    }

    /** Requester withdraws a pending approval. @return array<string, mixed> */
    public function cancel(string $approvalId): array
    {
        return DB::transaction(function () use ($approvalId) {
            $a = DB::table('approval')->where('id', Ids::toBinary($approvalId))->lockForUpdate()->first();
            if (! $a || $a->action === null) {
                throw ApiProblem::notFound('not_found', 'Approval not found.');
            }
            if (Ids::fromBinary($a->requested_by) !== Authz::staffId()) {
                throw ApiProblem::forbidden('permission_denied', 'Only the requester can cancel an approval request.');
            }
            if ($a->status !== 'PENDING') {
                throw ApiProblem::conflict('approval_already_decided', 'This approval has already been '.strtolower($a->status).'.');
            }
            $payload = json_decode((string) $a->payload, true) ?: [];
            ($this->handlers[$a->action] ?? null)?->discarded($a, $payload, 'CANCELLED');
            DB::table('approval')->where('id', $a->id)->update(['status' => 'CANCELLED', 'decided_at' => Fmt::now(), 'row_version' => $a->row_version + 1]);
            Audit::record($a->action.'.cancel', 'Approval', $approvalId, old: ['status' => 'PENDING'], new: ['status' => 'CANCELLED'], facilityUnitId: Ids::fromBinary($a->facility_unit_id), approvalId: $approvalId);
            $row = DB::table('approval')->where('id', $a->id)->first();
            $this->broadcastDecided($row, false, $payload);

            return $this->present($row);
        });
    }

    public function find(string $approvalId): object
    {
        $a = DB::table('approval')->where('id', Ids::toBinary($approvalId))->first();
        if (! $a || $a->action === null) {
            throw ApiProblem::notFound('not_found', 'Approval not found.');
        }
        $fid = Ids::fromBinary($a->facility_unit_id);
        $me = Authz::staffId();
        if (Ids::fromBinary($a->requested_by) !== $me && ! Authz::can($a->required_permission, $fid)) {
            throw ApiProblem::forbidden('permission_denied', 'You may not view this approval.');
        }

        return $a;
    }

    /** @return array<string, mixed> */
    public function list(Request $request): array
    {
        $me = Authz::staffId();
        $q = DB::table('approval')->whereNotNull('action')->where('organization_id', Ids::toBinary(Tenant::organizationId()));
        $filter = (array) $request->query('filter', []);
        $statuses = array_filter(array_map('strtoupper', explode(',', (string) ($filter['status'] ?? 'PENDING'))));
        $q->whereIn('status', $statuses);
        if (! empty($filter['facilityId'])) {
            $q->where('facility_unit_id', Ids::toBinary($filter['facilityId']));
        }
        $scope = $request->query('scope');
        if ($scope === 'mine') {
            $q->where('requested_by', Ids::toBinary($me));
        } elseif ($scope === 'approvable') {
            $q->where('requested_by', '!=', Ids::toBinary($me));
        } else {
            $q->where(fn ($w) => $w->where('requested_by', Ids::toBinary($me))->orWhere('requested_by', '!=', Ids::toBinary($me)));
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');
        $can = [];
        $items = $page->items->filter(function ($a) use ($me, $scope, &$can) {
            $mine = Ids::fromBinary($a->requested_by) === $me;
            if ($mine && $scope !== 'approvable') {
                return true;
            }
            $key = $a->required_permission.'@'.Ids::fromBinary($a->facility_unit_id);

            return $can[$key] ??= Authz::can($a->required_permission, Ids::fromBinary($a->facility_unit_id));
        })->values();
        $arr = $page->toArray();

        return ['items' => $items->map(fn ($a) => $this->present($a))->all(), 'nextCursor' => $arr['nextCursor']];
    }

    /** @return array<string, mixed> */
    public function present(object $a): array
    {
        $name = DB::table('staff')->where('id', $a->requested_by)->selectRaw("TRIM(CONCAT(first_name, ' ', last_name)) as n")->value('n');
        $status = $a->status === 'PENDING' && $a->expires_at !== null && $a->expires_at < Fmt::now() ? 'EXPIRED' : $a->status;

        return [
            'id' => Ids::fromBinary($a->id),
            'action' => $a->action,
            'entityType' => $a->entity_type,
            'entityId' => Fmt::u($a->entity_id),
            'facilityId' => Fmt::u($a->facility_unit_id),
            'status' => $status,
            'requestedByStaffId' => Ids::fromBinary($a->requested_by),
            'requestedByName' => $name,
            'requestedAt' => Fmt::ts($a->created_at),
            'reason' => $a->reason,
            'amount' => $a->amount === null ? null : Fmt::money($a->amount),
            'summary' => $a->summary,
            'decidedByStaffId' => Fmt::u($a->approved_by),
            'decidedAt' => Fmt::ts($a->decided_at),
            'decisionNote' => $a->decision_note,
            'requiredPermission' => $a->required_permission,
        ];
    }

    // ---- realtime -------------------------------------------------------------------------------------------------

    private function broadcastRequested(object $a, string $facilityId): void
    {
        $devices = $this->approverDevices($a->required_permission, $facilityId, Ids::fromBinary($a->requested_by));
        if ($devices === []) {
            return;
        }
        event(new ApprovalRequested(array_map(fn ($d) => 'device.'.$d, $devices), ['approval' => $this->present($a)]));
    }

    /** @param array<string, mixed> $payload */
    private function broadcastDecided(object $a, bool $applied, array $payload): void
    {
        if ($a->requested_device_id === null) {
            return;
        }
        event(new ApprovalDecided(['device.'.Ids::fromBinary($a->requested_device_id)], [
            'approval' => $this->present($a), 'applied' => $applied, 'orderId' => $payload['orderId'] ?? null,
        ]));
    }

    /** Devices where a staff member holding the permission at the facility currently has a live session. @return list<string> */
    private function approverDevices(string $permission, string $facilityId, string $exceptStaff): array
    {
        $rows = DB::table('session as s')
            ->join('user_account as ua', 'ua.id', '=', 's.user_account_id')
            ->whereNotNull('s.device_id')->whereNull('s.revoked_at')->where('s.expires_at', '>', Fmt::now())
            ->where('ua.staff_id', '!=', Ids::toBinary($exceptStaff))
            ->get(['ua.staff_id', 's.device_id']);
        $out = [];
        $can = [];
        foreach ($rows as $r) {
            $sid = Ids::fromBinary($r->staff_id);
            $can[$sid] ??= $this->safeCan($sid, $permission, $facilityId);
            if ($can[$sid]) {
                $out[Ids::fromBinary($r->device_id)] = true;
            }
        }

        return array_keys($out);
    }

    private function safeCan(string $staffId, string $permission, string $facilityId): bool
    {
        try {
            return $this->permissions->can($staffId, $permission, Scope::facility($facilityId));
        } catch (\Throwable) {
            return false;
        }
    }
}
