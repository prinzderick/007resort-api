<?php

namespace App\Domain\Identity\Demo;

use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * One staff user per role (+ a few extra attendants) with DOCUMENTED DEV-ONLY credentials:
 *   PIN 1234 for everyone, password "Dev#Pass1234" for everyone (also loginable by staff number, e.g. S-0001).
 * NEVER seed these in production (r007:demo-seed refuses).
 */
class IdentityDemoSeeder implements DemoSeeder
{
    public const PIN = '1234';

    public const PASSWORD = 'Dev#Pass1234';

    public function priority(): int
    {
        return 20;
    }

    /** username => [staffNumber, first, last, roleCode, scopeLevel, facilityCode|null, extra facility codes] */
    private function staff(): array
    {
        return [
            'wait1' => ['S-0001', 'Amaka', 'Okoro', 'WAIT_STAFF', 'FACILITY_UNIT', ['RESTAURANT', 'INDOOR_CLUB']],
            'wait2' => ['S-0002', 'Tamuno', 'Ebi', 'WAIT_STAFF', 'FACILITY_UNIT', ['POOL_BAR', 'BUSH_BAR', 'EVENT_CENTRE']],
            'bartender1' => ['S-0003', 'Ibinabo', 'Preye', 'BARTENDER', 'FACILITY_UNIT', ['POOL_BAR', 'BUSH_BAR', 'INDOOR_CLUB']],
            'kitchen1' => ['S-0004', 'Chidi', 'Nwosu', 'KITCHEN_STAFF', 'FACILITY_UNIT', ['MAIN_KITCHEN', 'RESTAURANT']],
            'cashier1' => ['S-0005', 'Ngozi', 'Eze', 'CASHIER', 'FACILITY_UNIT', ['RECEPTION']],
            'cashier2' => ['S-0006', 'Emeka', 'Obi', 'CASHIER', 'FACILITY_UNIT', ['RESTAURANT', 'CAFE', 'SUPERMARKET', 'SUPER_STORE']],
            'storekeeper1' => ['S-0007', 'Bola', 'Adebayo', 'STOREKEEPER', 'FACILITY_UNIT', ['MAIN_STORE', 'SPORTS_STORE', 'SUPERMARKET']],
            'supervisor1' => ['S-0008', 'Funke', 'Balogun', 'UNIT_SUPERVISOR', 'SITE', []],
            'procurement1' => ['S-0009', 'Yusuf', 'Bello', 'PROCUREMENT', 'SITE', []],
            'accountant1' => ['S-0010', 'Halima', 'Sani', 'ACCOUNTANT', 'SITE', []],
            'manager1' => ['S-0011', 'Tunde', 'Ogunleye', 'MANAGER', 'SITE', []],
            'itadmin1' => ['S-0012', 'Kelechi', 'Umeh', 'IT_ADMIN', 'SITE', []],
            'owner1' => ['S-0013', 'Ebiye', 'Owei', 'OWNER', 'ORGANIZATION', []],
        ];
    }

    public function run(DemoContext $ctx): void
    {
        $rows = [];
        foreach ($this->staff() as $username => [$number, $first, $last, $roleCode, $level, $facilities]) {
            $staff = Staff::query()->updateOrCreate(['id' => DemoIds::staff($username)], [
                'organization_id' => DemoIds::org(), 'site_id' => DemoIds::site(), 'staff_number' => $number,
                'first_name' => $first, 'last_name' => $last, 'email' => $username.'@demo.007resort.test', 'is_active' => 1, 'deleted_at' => null,
            ]);
            $account = UserAccount::query()->updateOrCreate(['staff_id' => $staff->id], ['username' => $username, 'is_active' => 1, 'failed_login_count' => 0, 'locked_until' => null]);
            foreach ([Credential::PASSWORD => self::PASSWORD, Credential::PIN => self::PIN] as $type => $secret) {
                Credential::query()->where('user_account_id', $account->id)->where('credential_type', $type)->update(['is_active' => 0]);
                Credential::create(['user_account_id' => $account->id, 'credential_type' => $type, 'credential_hash' => Hash::make($secret), 'algorithm' => 'ARGON2ID']);
            }

            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $targets = $level === 'FACILITY_UNIT' ? $facilities : [null];
            foreach ($targets as $f) {
                RoleAssignment::query()->updateOrCreate(['id' => DemoIds::of("ra:{$username}:{$roleCode}:".($f ?? $level))], [
                    'staff_id' => $staff->id, 'role_id' => $role->id, 'scope_level' => $level, 'organization_id' => DemoIds::org(),
                    'site_id' => $level === 'ORGANIZATION' ? null : DemoIds::site(), 'facility_unit_id' => $f ? DemoIds::facility($f) : null,
                    'is_active' => 1, 'deleted_at' => null, 'granted_at' => now('UTC'),
                ]);
            }
            $rows[] = [$username, $number, $roleCode, $level === 'FACILITY_UNIT' ? implode(', ', $facilities) : $level, self::PIN, self::PASSWORD];
        }
        $ctx->table('DEV-ONLY staff logins (login with identifier = username or staff number; credentialType PIN or PASSWORD)', ['username', 'staff no.', 'role', 'scope', 'PIN', 'password'], $rows);
        $ctx->info('  '.count($rows).' staff users');
    }
}
