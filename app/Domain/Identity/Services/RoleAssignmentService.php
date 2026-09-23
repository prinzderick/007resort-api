<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Grant / revoke scoped roles. Idempotent by construction: role_assignment.active_key (UNIQUE) allows only one ACTIVE
 * assignment per (staff, role, scope), so repeated or concurrent grants converge on one row. Anti-escalation via PrivilegeGuard.
 */
class RoleAssignmentService
{
    /** contract scopeType => DB scope_level */
    private const SCOPES = ['ORGANIZATION' => 'ORGANIZATION', 'SITE' => 'SITE', 'FACILITY' => 'FACILITY_UNIT'];

    public function __construct(private readonly PrivilegeGuard $guard, private readonly SessionRevoker $sessions) {}

    /** @return array{0: RoleAssignment, 1: bool} assignment, created? */
    public function grant(Staff $target, string $rolePublicId, string $scopeType, string $scopeId): array
    {
        $actor = RequestContext::staffId();
        $role = Role::query()->where('public_id', $rolePublicId)->first() ?? throw ApiProblem::notFound('not_found', 'Role was not found.');
        [$level, $org, $site, $facility, $scope] = $this->resolveScope($target, $scopeType, $scopeId);
        $this->guard->assertCanGrantRole($actor, (int) $role->id, $scope);

        try {
            return DB::transaction(function () use ($target, $role, $level, $org, $site, $facility, $actor): array {
                $existing = RoleAssignment::query()->where('staff_id', $target->id)->where('role_id', $role->id)->where('scope_level', $level)
                    ->where('is_active', 1)->whereNull('deleted_at')
                    ->where(fn ($q) => $facility ? $q->where('facility_unit_id', $facility) : ($site ? $q->where('site_id', $site)->whereNull('facility_unit_id') : $q->whereNull('site_id')->whereNull('facility_unit_id')))
                    ->first(); // plain read on purpose: a locking read here would deadlock racing grants (S gap locks vs insert intent)
                if ($existing !== null) {
                    return [$existing, false];
                }
                $a = RoleAssignment::create([
                    'staff_id' => $target->id, 'role_id' => $role->id, 'scope_level' => $level, 'organization_id' => $org,
                    'site_id' => $site, 'facility_unit_id' => $facility, 'granted_by' => $actor, 'granted_at' => now('UTC'),
                ]);
                $view = $this->auditView($a, $role);
                Audit::record('role_assignment.grant', 'RoleAssignment', $a->id, new: $view, facilityUnitId: $facility);
                Outbox::record('StaffRosterUpdated', 'RoleAssignment', $a->id, ['change' => 'GRANTED'] + $view, 1, facilityId: $facility);

                return [$a, true];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) { // concurrent identical grant won the unique key
                // locking read: under REPEATABLE READ (e.g. inside the `idempotent` middleware's transaction) a plain SELECT would
                // still see the pre-race snapshot and miss the winner's committed row.
                $a = RoleAssignment::query()->where('staff_id', $target->id)->where('role_id', $role->id)->where('is_active', 1)->whereNull('deleted_at')->sharedLock()->firstOrFail();

                return [$a, false];
            }
            throw $e;
        }
    }

    public function revoke(Staff $target, string $assignmentId): void
    {
        $actor = RequestContext::staffId();
        DB::transaction(function () use ($target, $assignmentId, $actor): void {
            $a = RoleAssignment::query()->where('staff_id', $target->id)->whereKey($assignmentId)->lockForUpdate()->first()
                ?? throw ApiProblem::notFound('not_found', 'Role assignment was not found.');
            if (! $a->is_active || $a->deleted_at !== null) {
                return; // already revoked: idempotent
            }
            $role = Role::query()->findOrFail($a->role_id);
            $scope = $a->facility_unit_id ? Scope::facility($a->facility_unit_id) : ($a->site_id ? Scope::site($a->site_id) : Scope::organization($a->organization_id));
            $this->guard->assertCanGrantRole($actor, (int) $role->id, $scope);

            $old = $this->auditView($a, $role);
            $a->forceFill(['is_active' => 0, 'deleted_at' => now('UTC'), 'row_version' => $a->row_version + 1])->save();
            Audit::record('role_assignment.revoke', 'RoleAssignment', $a->id, old: $old, new: $old + ['revoked' => true], facilityUnitId: $a->facility_unit_id);
            Outbox::record('StaffRosterUpdated', 'RoleAssignment', $a->id, ['change' => 'REVOKED'] + $old, (int) $a->row_version, facilityId: $a->facility_unit_id);
        });
    }

    /** Contract RoleAssignment. @return array<string, mixed> */
    public function present(RoleAssignment $a, ?Role $role = null): array
    {
        $role ??= Role::query()->find($a->role_id);
        [$type, $scopeId] = match ($a->scope_level) {
            'FACILITY_UNIT' => ['FACILITY', $a->facility_unit_id],
            'SITE' => ['SITE', $a->site_id],
            default => ['ORGANIZATION', $a->organization_id],
        };

        return [
            'id' => $a->id, 'staffId' => $a->staff_id, 'roleId' => $role->public_id, 'scopeType' => $type, 'scopeId' => $scopeId,
            'grantedByStaffId' => $a->granted_by, 'grantedAt' => $a->granted_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'revokedAt' => $a->deleted_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'roleCode' => $role->code,
        ];
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: ?string, 4: Scope} level, org, site, facility, scope */
    private function resolveScope(Staff $target, string $scopeType, string $scopeId): array
    {
        $level = self::SCOPES[$scopeType] ?? throw ApiProblem::unprocessable('validation_failed', 'Invalid scopeType.', ['scopeType' => ['Must be ORGANIZATION, SITE or FACILITY.']]);
        $scopeId = Ids::normalize($scopeId);

        if ($level === 'ORGANIZATION') {
            if ($scopeId !== $target->organization_id) {
                throw ApiProblem::unprocessable('validation_failed', 'Unknown organization.', ['scopeId' => ['Unknown organization.']]);
            }

            return [$level, $scopeId, null, null, Scope::organization($scopeId)];
        }
        if ($level === 'SITE') {
            $site = DB::table('site')->where('id', Ids::toBinary($scopeId))->first();
            if (! $site || Ids::fromBinary($site->organization_id) !== $target->organization_id) {
                throw ApiProblem::unprocessable('validation_failed', 'Unknown site.', ['scopeId' => ['Unknown site.']]);
            }

            return [$level, $target->organization_id, $scopeId, null, Scope::site($scopeId)];
        }
        $f = DB::table('facility_unit')->where('id', Ids::toBinary($scopeId))->whereNull('deleted_at')->first();
        if (! $f || Ids::fromBinary($f->organization_id) !== $target->organization_id) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown facility.', ['scopeId' => ['Unknown facility.']]);
        }

        return [$level, $target->organization_id, Ids::fromBinary($f->site_id), $scopeId, Scope::facility($scopeId)];
    }

    /** @return array<string, mixed> */
    private function auditView(RoleAssignment $a, Role $role): array
    {
        return [
            'assignmentId' => $a->id, 'staffId' => $a->staff_id, 'roleCode' => $role->code, 'scopeLevel' => $a->scope_level,
            'organizationId' => $a->organization_id, 'siteId' => $a->site_id, 'facilityUnitId' => $a->facility_unit_id,
        ];
    }
}
