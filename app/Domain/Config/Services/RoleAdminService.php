<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\ConfigChange;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Role administration for the permission-matrix UI. Anti-escalation: nobody can grant a permission they do not hold themselves, nor edit a role
 * that contains permissions they do not hold (strict: no "operational" exemption here). The OWNER role is immutable (tests assert it holds every permission).
 */
class RoleAdminService
{
    private const GROUP_LABELS = [
        'order' => 'Orders', 'payment' => 'Payments', 'refund' => 'Refunds', 'cash_session' => 'Cash sessions', 'cash_movement' => 'Cash sessions', 'receipt' => 'Receipts', 'tab' => 'Tabs',
        'prep_ticket' => 'Kitchen & bar screens', 'inventory' => 'Inventory', 'supplier' => 'Inventory', 'catalog' => 'Catalogue', 'pricing' => 'Catalogue', 'booking' => 'Booking',
        'ticket' => 'Tickets', 'membership' => 'Membership', 'attendance' => 'Attendance', 'report' => 'Reports', 'staff' => 'Staff', 'role_assignment' => 'Staff', 'role' => 'Staff',
        'device' => 'Devices', 'config' => 'Configuration', 'facility' => 'Configuration', 'table' => 'Configuration', 'ticket_type' => 'Configuration', 'settings' => 'Configuration',
        'audit' => 'Security & audit', 'security_event' => 'Security & audit', 'session' => 'Security & audit', 'settlement' => 'Finance', 'finance' => 'Finance',
    ];

    public function __construct(private readonly PermissionChecker $permissions) {}

    private function actor(): string
    {
        return RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
    }

    public function find(string $publicId, bool $lock = false): Role
    {
        $q = Ids::isUuid($publicId) ? Role::query()->where('public_id', Ids::normalize($publicId)) : null;
        $lock && $q?->lockForUpdate();

        return $q?->first() ?? throw ApiProblem::notFound('not_found', 'Role was not found.');
    }

    /** @return array<string, mixed> */
    public function matrix(Role $role): array
    {
        $granted = DB::table('role_permission')->where('role_id', $role->id)->pluck('requires_approval', 'permission_id');
        $held = $this->heldCodes();
        $groups = [];
        foreach (DB::table('permission')->orderBy('code')->get(['id', 'code', 'description']) as $p) {
            $prefix = explode('.', $p->code)[0];
            $label = self::GROUP_LABELS[$prefix] ?? ucfirst(str_replace('_', ' ', $prefix));
            $groups[$label][] = ['code' => $p->code, 'description' => $p->description, 'granted' => $granted->has($p->id), 'requiresApproval' => (bool) ($granted[$p->id] ?? false), 'grantable' => isset($held[$p->code])];
        }
        ksort($groups);

        return [
            'role' => ['id' => $role->public_id, 'code' => $role->code, 'name' => $role->name, 'description' => $role->description, 'system' => (bool) $role->is_system, 'editable' => $role->code !== 'OWNER', 'rowVersion' => (int) $role->row_version,
                'assignments' => (int) DB::table('role_assignment')->where('role_id', $role->id)->where('is_active', 1)->whereNull('deleted_at')->count()],
            'groups' => array_map(fn ($label, $items) => ['group' => $label, 'items' => $items], array_keys($groups), array_values($groups)),
        ];
    }

    /** @return array<string, true> permission codes the caller holds (somewhere) */
    private function heldCodes(): array
    {
        $out = [];
        foreach ($this->permissions->effective($this->actor()) as $g) {
            $out[$g->permission] = true;
        }

        return $out;
    }

    /**
     * @param  list<array{code: string, requiresApproval?: bool}>  $permissions  the complete set
     * @return array<string, mixed> matrix
     */
    public function setPermissions(string $publicId, array $permissions, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($publicId, $permissions, $ifMatch): array {
            $role = $this->find($publicId, true);
            if ($role->code === 'OWNER') {
                throw ApiProblem::forbidden('role_immutable', 'The Owner role always holds every permission and cannot be edited.');
            }
            Concurrency::assertVersion((int) $role->row_version, $ifMatch, 'role');
            $wanted = [];
            $errors = [];
            foreach ($permissions as $i => $p) {
                $code = (string) ($p['code'] ?? '');
                if (isset($wanted[$code])) {
                    $errors["permissions.{$i}.code"] = ["Duplicate permission {$code}."];
                }
                $wanted[$code] = (bool) ($p['requiresApproval'] ?? false);
            }
            $ids = DB::table('permission')->whereIn('code', array_keys($wanted))->pluck('id', 'code');
            foreach ($permissions as $i => $p) {
                if (! isset($ids[$p['code']])) {
                    $errors["permissions.{$i}.code"] = ["Unknown permission {$p['code']}."];
                }
            }
            if ($errors !== []) {
                throw ApiProblem::unprocessable('validation_failed', 'Invalid permission list.', $errors);
            }
            $current = DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->pluck('rp.requires_approval', 'p.code')->map(fn ($v) => (bool) $v)->all();
            $this->assertNoEscalation(array_keys($current), array_keys($wanted));
            $added = array_values(array_diff(array_keys($wanted), array_keys($current)));
            $removed = array_values(array_diff(array_keys($current), array_keys($wanted)));
            $approvalChanged = array_keys(array_filter($wanted, fn ($v, $c) => isset($current[$c]) && $current[$c] !== $v, ARRAY_FILTER_USE_BOTH));
            if ($added === [] && $removed === [] && $approvalChanged === []) {
                return $this->matrix($role);
            }
            DB::table('role_permission')->where('role_id', $role->id)->delete();
            foreach ($wanted as $code => $needsApproval) {
                DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $ids[$code], 'requires_approval' => $needsApproval ? 1 : 0]);
            }
            $version = (int) $role->row_version + 1;
            DB::table('role')->where('id', $role->id)->update(['row_version' => $version]);
            ConfigChange::record('config.role.permissions.set', 'Role', $role->public_id, ['permissions' => array_keys($current)], ['permissions' => array_keys($wanted), 'added' => $added, 'removed' => $removed, 'approvalChanged' => $approvalChanged],
                'rolePermissions', ['code' => $role->code, 'permissions' => $wanted], $version);

            return $this->matrix(Role::query()->find($role->id));
        });
    }

    /** @param list<string> $current @param list<string> $target */
    private function assertNoEscalation(array $current, array $target): void
    {
        $held = $this->heldCodes();
        $missing = array_values(array_unique(array_filter([...$current, ...$target], fn ($c) => ! isset($held[$c]))));
        if ($missing !== []) {
            sort($missing);
            throw ApiProblem::forbidden('privilege_escalation', 'You cannot change a role containing permissions you do not hold yourself: '.implode(', ', $missing).'.', ['missingPermissions' => $missing]);
        }
    }

    /** @param array<string, mixed> $in name, description?, permissions?: list<string> @return array<string, mixed> */
    public function create(array $in): array
    {
        try {
            return DB::transaction(function () use ($in): array {
                $codes = array_values(array_unique($in['permissions'] ?? []));
                $ids = DB::table('permission')->whereIn('code', $codes)->pluck('id', 'code');
                $unknown = array_values(array_diff($codes, $ids->keys()->all()));
                if ($unknown !== []) {
                    throw ApiProblem::unprocessable('validation_failed', 'Unknown permission.', ['permissions' => ['Unknown: '.implode(', ', $unknown)]]);
                }
                $this->assertNoEscalation([], $codes);
                $base = 'CUSTOM_'.trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper($in['name'])), '_');
                $code = substr($base, 0, 56);
                for ($n = 2; Role::query()->where('code', $code)->exists(); $n++) {
                    $code = substr($base, 0, 52).'_'.$n;
                }
                $role = Role::create(['code' => $code, 'name' => $in['name'], 'description' => $in['description'] ?? null, 'is_system' => 0, 'row_version' => 1]);
                foreach ($ids as $pid) {
                    DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $pid, 'requires_approval' => 0]);
                }
                ConfigChange::record('config.role.create', 'Role', $role->public_id, null, ['code' => $code, 'name' => $in['name'], 'permissions' => $codes], 'rolePermissions',
                    ['code' => $code, 'name' => $in['name'], 'description' => $in['description'] ?? null, 'permissions' => array_fill_keys($codes, false)], 1);

                return $this->matrix($role->refresh());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('role_code_taken', 'A role with that name already exists.');
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $in name?, description? @return array<string, mixed> */
    public function update(string $publicId, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($publicId, $in, $ifMatch): array {
            $role = $this->find($publicId, true);
            if ($role->is_system) {
                throw ApiProblem::forbidden('role_immutable', 'Built-in roles cannot be renamed. Create a custom role instead (their permissions can be edited).');
            }
            Concurrency::assertVersion((int) $role->row_version, $ifMatch, 'role');
            $this->assertNoEscalation(DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->pluck('p.code')->all(), []);
            $set = array_intersect_key($in, array_flip(['name', 'description']));
            $set = array_filter($set, fn ($v, $k) => $v !== $role->{$k}, ARRAY_FILTER_USE_BOTH);
            if ($set === []) {
                return $this->matrix($role);
            }
            $version = (int) $role->row_version + 1;
            DB::table('role')->where('id', $role->id)->update($set + ['row_version' => $version]);
            ConfigChange::record('config.role.update', 'Role', $role->public_id, array_intersect_key($role->getAttributes(), $set), $set, 'rolePermissions', ['code' => $role->code] + $set, $version);

            return $this->matrix(Role::query()->find($role->id));
        });
    }

    public function delete(string $publicId): void
    {
        DB::transaction(function () use ($publicId): void {
            $role = $this->find($publicId, true);
            if ($role->is_system) {
                throw ApiProblem::forbidden('role_immutable', 'Built-in roles cannot be deleted.');
            }
            if (DB::table('role_assignment')->where('role_id', $role->id)->exists()) {
                throw ApiProblem::conflict('role_in_use', 'This role is (or was) assigned to staff, so it cannot be deleted. Remove its assignments, or keep it and clear its permissions.');
            }
            $this->assertNoEscalation(DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->pluck('p.code')->all(), []);
            $perms = DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->pluck('p.code')->all();
            DB::table('role_permission')->where('role_id', $role->id)->delete();
            DB::table('role')->where('id', $role->id)->delete();
            ConfigChange::record('config.role.delete', 'Role', $role->public_id, ['code' => $role->code, 'name' => $role->name, 'permissions' => $perms], ['deleted' => true], 'rolePermissions', ['code' => $role->code, 'deleted' => true], (int) $role->row_version + 1);
        });
    }
}
