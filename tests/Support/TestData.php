<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Domain\Organization\Models\FacilityUnit;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Site;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Test fixtures against the real MySQL schema. Passwords here are throwaway test values. */
final class TestData
{
    public const PASSWORD = 'Test-Pass-123!';

    public const PIN = '4321';

    /** @return array{org: string, site: string} */
    public static function tenant(string $name = 'Test Resort'): array
    {
        $org = Organization::create(['name' => $name]);
        $site = Site::create(['organization_id' => $org->id, 'name' => $name.' Site']);

        return ['org' => $org->id, 'site' => $site->id];
    }

    public static function facility(array $tenant, string $code, ?string $parentId = null): FacilityUnit
    {
        return FacilityUnit::create([
            'organization_id' => $tenant['org'], 'site_id' => $tenant['site'], 'parent_id' => $parentId,
            'code' => $code, 'name' => ucfirst($code),
        ]);
    }

    public static function staff(array $tenant, string $username, string $password = self::PASSWORD, ?string $pin = self::PIN, bool $active = true): Staff
    {
        $staff = Staff::create([
            'organization_id' => $tenant['org'], 'site_id' => $tenant['site'],
            'staff_number' => strtoupper($username), 'first_name' => ucfirst($username), 'last_name' => 'Tester', 'is_active' => $active,
        ]);
        $account = UserAccount::create(['staff_id' => $staff->id, 'username' => $username]);
        Credential::create(['user_account_id' => $account->id, 'credential_type' => Credential::PASSWORD, 'credential_hash' => Hash::make($password)]);
        if ($pin !== null) {
            Credential::create(['user_account_id' => $account->id, 'credential_type' => Credential::PIN, 'credential_hash' => Hash::make($pin)]);
        }

        return $staff;
    }

    /** Assign a seeded role by code (test setup only — application code never branches on role code). */
    public static function assign(Staff $staff, string $roleCode, string $level = 'SITE', ?string $facilityId = null): RoleAssignment
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        return RoleAssignment::create([
            'staff_id' => $staff->id, 'role_id' => $role->id, 'scope_level' => $level,
            'organization_id' => $staff->organization_id,
            'site_id' => $level === 'ORGANIZATION' ? null : $staff->site_id,
            'facility_unit_id' => $level === 'FACILITY_UNIT' ? $facilityId : null,
        ]);
    }

    /** A custom role (any name/code!) bundling exactly the given permission codes. */
    public static function customRole(string $code, string $name, array $permissionCodes): Role
    {
        $role = Role::create(['code' => $code, 'name' => $name]);
        foreach ($permissionCodes as $p) {
            $pid = DB::table('permission')->where('code', $p)->value('id');
            DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => $pid]);
        }

        return $role;
    }

    public static function assignRole(Staff $staff, Role $role, string $level = 'SITE', ?string $facilityId = null): RoleAssignment
    {
        return RoleAssignment::create([
            'staff_id' => $staff->id, 'role_id' => $role->id, 'scope_level' => $level,
            'organization_id' => $staff->organization_id,
            'site_id' => $level === 'ORGANIZATION' ? null : $staff->site_id,
            'facility_unit_id' => $level === 'FACILITY_UNIT' ? $facilityId : null,
        ]);
    }

    /** Wipe all non-catalog rows (for tests that cannot run inside a rolled-back transaction). */
    public static function wipe(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        // Append-only ledger tables carry BEFORE DELETE triggers that (correctly) refuse DELETE; TRUNCATE is DDL and bypasses them.
        $ledger = array_map(fn ($r) => $r->t, DB::select(
            "SELECT DISTINCT EVENT_OBJECT_TABLE AS t FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_MANIPULATION = 'DELETE'"
        ));
        foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $t = array_values((array) $row)[0];
            if (! in_array($t, ['migrations', 'capability_type', 'role', 'permission', 'role_permission', 'audit_chain_head'], true)) {
                in_array($t, $ledger, true) ? DB::statement("TRUNCATE TABLE `{$t}`") : DB::table($t)->delete();
            }
        }
        DB::table('role')->whereNotIn('code', ['WAIT_STAFF', 'BARTENDER', 'KITCHEN_STAFF', 'CASHIER', 'STOREKEEPER', 'UNIT_SUPERVISOR', 'PROCUREMENT', 'ACCOUNTANT', 'MANAGER', 'IT_ADMIN', 'OWNER', 'MARKETING'])->get(['id'])
            ->each(fn ($r) => DB::table('role_permission')->where('role_id', $r->id)->delete());
        DB::table('role')->whereNotIn('code', ['WAIT_STAFF', 'BARTENDER', 'KITCHEN_STAFF', 'CASHIER', 'STOREKEEPER', 'UNIT_SUPERVISOR', 'PROCUREMENT', 'ACCOUNTANT', 'MANAGER', 'IT_ADMIN', 'OWNER', 'MARKETING'])->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        self::resetAuditChain();
    }

    /** After deleting audit_log rows in a test, point the chain head back at genesis. */
    public static function resetAuditChain(): void
    {
        DB::table('audit_chain_head')->updateOrInsert(['id' => 1], ['tail_seq' => 0, 'tail_hash' => str_repeat('0', 64)]);
    }

    public static function bin(string $uuid): string
    {
        return Ids::toBinary($uuid);
    }
}
