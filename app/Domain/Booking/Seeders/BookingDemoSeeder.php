<?php

namespace App\Domain\Booking\Seeders;

use App\Domain\Booking\Demo\BookingDemoData;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Domain\Organization\Models\FacilityUnit;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * STANDALONE demo data (this module without the Organization/Identity/Devices demo seeders). Idempotent.
 *
 * Facilities:  RECEPTION, SPORTS_ARENA > SPORTS_ENTRANCE, SPORTS_STORE, POOL.
 * People:      users reception / entrance / store / pool (dev password below — NEVER use outside dev).
 * Everything else (resources, ticket types, catalog products, sample entitlements) comes from {@see BookingDemoData}, shared with the
 * integrated seeder (Booking\Demo\BookingDemoSeeder, used when `App\Console\Commands\DemoSeed` exists).
 */
class BookingDemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Pass-007!';

    public const PIN = '2468';

    public function run(): void
    {
        $site = Site::query()->orderBy('created_at')->first();
        if ($site === null) {
            $org = Organization::create(['name' => '007 Resort & Spa']);
            $site = Site::create(['organization_id' => $org->id, 'name' => '007 Resort & Spa']);
        }
        $t = ['org' => $site->organization_id, 'site' => $site->id];
        $reception = $this->facility($t, 'RECEPTION', 'Main Reception');
        $arena = $this->facility($t, 'SPORTS_ARENA', 'Sports Arena');
        $entrance = $this->facility($t, 'SPORTS_ENTRANCE', 'Sports Entrance', $arena->id);
        $store = $this->facility($t, 'SPORTS_STORE', 'Sports Store');
        $pool = $this->facility($t, 'POOL', 'Pool');

        $f = $t + ['reception' => $reception->id, 'arena' => $arena->id, 'store' => $store->id, 'pool' => $pool->id];
        $res = fn (string $code, string $name, string $mode, int $cap, string $price, string $sku, bool $clinic = false) => compact('code', 'name', 'mode', 'price', 'sku', 'clinic') + ['capacity' => $cap, 'facility' => $arena->id];
        $data = new BookingDemoData;
        $data->seed($f, [
            $res('FOOTBALL', 'Football Pitch', BookableResource::MODE_WHOLE, 1, '25000.0000', 'FEE-FOOTBALL'),
            $res('TENNIS-1', 'Lawn Tennis Court 1', BookableResource::MODE_SLOT, 1, '5000.0000', 'FEE-TENNIS'),
            $res('TENNIS-2', 'Lawn Tennis Court 2', BookableResource::MODE_SLOT, 1, '5000.0000', 'FEE-TENNIS'),
            $res('BASKETBALL', 'Basketball Court', BookableResource::MODE_SLOT, 1, '8000.0000', 'FEE-BASKETBALL'),
            $res('TENNIS-CLINIC', 'Tennis Clinic', BookableResource::MODE_CAPACITY, 8, '3000.0000', 'FEE-TENNIS-CLINIC', true),
        ], fn (string $line) => $this->command?->line($line));

        DB::transaction(function () use ($t, $reception, $entrance, $store, $pool) {
            $attendant = Role::query()->where('code', 'SPORTS_ATTENDANT')->first() ?? Role::create(['code' => 'SPORTS_ATTENDANT', 'name' => 'Sports / pool attendant', 'description' => 'Scans tickets and releases/returns rental items']);
            foreach (['ticket.view', 'ticket.redeem', 'ticket.release', 'booking.view'] as $perm) {
                DB::statement('INSERT IGNORE INTO role_permission (role_id, permission_id) SELECT ?, id FROM permission WHERE code = ?', [$attendant->id, $perm]);
            }
            $cashier = Role::query()->where('code', 'CASHIER')->firstOrFail();
            $this->person($t, 'reception', $cashier, $reception->id);
            $this->person($t, 'entrance', $attendant, $entrance->id);
            $this->person($t, 'store', $attendant, $store->id);
            $this->person($t, 'pool', $attendant, $pool->id);
        });
        $this->command?->info('Booking demo seeded. Users: reception / entrance / store / pool — password '.self::PASSWORD.' (PIN '.self::PIN.'). Dev only.');
    }

    private function facility(array $t, string $code, string $name, ?string $parent = null): FacilityUnit
    {
        return FacilityUnit::query()->where('site_id', $t['site'])->where('code', $code)->first()
            ?? FacilityUnit::create(['organization_id' => $t['org'], 'site_id' => $t['site'], 'parent_id' => $parent, 'code' => $code, 'name' => $name]);
    }

    private function person(array $t, string $username, Role $role, string $facilityId): void
    {
        $account = UserAccount::query()->where('username', $username)->first();
        if ($account === null) {
            $staff = Staff::create(['organization_id' => $t['org'], 'site_id' => $t['site'], 'staff_number' => strtoupper('D-'.$username), 'first_name' => ucfirst($username), 'last_name' => 'Demo']);
            $account = UserAccount::create(['staff_id' => $staff->id, 'username' => $username]);
            Credential::create(['user_account_id' => $account->id, 'credential_type' => Credential::PASSWORD, 'credential_hash' => Hash::make(self::PASSWORD)]);
            Credential::create(['user_account_id' => $account->id, 'credential_type' => Credential::PIN, 'credential_hash' => Hash::make(self::PIN)]);
        }
        if (RoleAssignment::query()->where('staff_id', $account->staff_id)->where('role_id', $role->id)->doesntExist()) {
            RoleAssignment::create([
                'staff_id' => $account->staff_id, 'role_id' => $role->id, 'scope_level' => 'FACILITY_UNIT',
                'organization_id' => $t['org'], 'site_id' => $t['site'], 'facility_unit_id' => $facilityId,
            ]);
        }
    }
}
