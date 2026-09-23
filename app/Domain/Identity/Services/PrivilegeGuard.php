<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Auth\Scope;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Anti-escalation ("below one's own level", architecture/06 §2): you can only hand out / manage what you already hold.
 * Operational permissions (config identity.operational_permissions) are exempt; see that config comment.
 */
class PrivilegeGuard
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    /** The granter must hold every non-operational permission of the role, covering the scope of the assignment. */
    public function assertCanGrantRole(string $actorStaffId, int $roleId, ?Scope $scope): void
    {
        $needed = $this->nonOperational(DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)->pluck('p.code')->all());
        foreach ($needed as $code) {
            if (! $this->permissions->can($actorStaffId, $code, $scope)) {
                throw ApiProblem::forbidden('permission_denied', "You cannot grant a role containing '{$code}' because you do not hold it at that scope.", [
                    'permission' => $code, 'meta' => ['permission' => $code],
                ]);
            }
        }
    }

    /** Managing a staff member (credentials, status): the actor must hold every non-operational permission the target holds. */
    public function assertCanManageStaff(string $actorStaffId, string $targetStaffId): void
    {
        if ($actorStaffId === $targetStaffId) {
            return;
        }
        $targetPerms = $this->nonOperational($this->permissions->effective($targetStaffId)->pluck('permission')->unique()->all());
        foreach ($targetPerms as $code) {
            if (! $this->permissions->can($actorStaffId, $code)) {
                throw ApiProblem::forbidden('permission_denied', 'You cannot manage a staff member who holds more privileges than you.', [
                    'permission' => $code, 'meta' => ['permission' => $code],
                ]);
            }
        }
    }

    /** @param  list<string>  $codes @return list<string> */
    private function nonOperational(array $codes): array
    {
        return array_values(array_diff($codes, (array) config('identity.operational_permissions')));
    }
}
