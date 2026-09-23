<?php

namespace App\Domain\Booking\Demo;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Identity\Demo\IdentityDemoSeeder;
use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * INTEGRATED demo (picked up by `php artisan r007:demo-seed` once the Organization/Identity/Devices demo seeders exist).
 * Enriches THEIR facilities and bookable resources (ids from DemoIds) with prices, slot rules, availability, catalog products, pool
 * ticket types and two sample QR entitlements; adds attendant logins on their facilities. Idempotent.
 *
 * Runs after Organization 10, Identity 20, Devices 30 and Catalog 100.
 */
class BookingDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 110;
    }

    public function run(DemoContext $ctx): void
    {
        $f = [
            'org' => DemoIds::org(), 'site' => DemoIds::site(), 'reception' => DemoIds::facility('RECEPTION'), 'arena' => DemoIds::facility('SPORTS_ARENA'),
            'store' => DemoIds::facility('SPORTS_STORE'), 'pool' => DemoIds::facility('POOL_AREA'),
        ];
        $res = fn (string $facCode, string $code, string $name, string $mode, int $cap, string $price, string $sku, bool $clinic = false) => [
            'code' => $code, 'name' => $name, 'mode' => $mode, 'capacity' => $cap, 'price' => $price, 'sku' => $sku, 'clinic' => $clinic,
            'facility' => DemoIds::facility($facCode), 'id' => DemoIds::resource($facCode, $code),
        ];
        $data = new BookingDemoData;
        $data->seed($f, [
            $res('FOOTBALL', 'PITCH_1', 'Football Pitch 1', BookableResource::MODE_WHOLE, 1, '25000.0000', 'FEE-FOOTBALL'),
            $res('LAWN_TENNIS', 'COURT_1', 'Lawn Tennis Court 1', BookableResource::MODE_SLOT, 1, '5000.0000', 'FEE-TENNIS'),
            $res('LAWN_TENNIS', 'COURT_2', 'Lawn Tennis Court 2', BookableResource::MODE_SLOT, 1, '5000.0000', 'FEE-TENNIS'),
            $res('BASKETBALL', 'COURT_1', 'Basketball Court 1', BookableResource::MODE_SLOT, 1, '8000.0000', 'FEE-BASKETBALL'),
            $res('LAWN_TENNIS', 'CLINIC', 'Tennis Clinic', BookableResource::MODE_CAPACITY, 8, '3000.0000', 'FEE-TENNIS-CLINIC', true),
        ], fn (string $line) => $ctx->info($line));

        $this->receptionPaysFirst($f['reception']);

        $attendant = Role::query()->where('code', 'SPORTS_ATTENDANT')->first() ?? Role::create(['code' => 'SPORTS_ATTENDANT', 'name' => 'Sports / pool attendant', 'description' => 'Scans tickets and releases/returns rental items']);
        foreach (['ticket.view', 'ticket.redeem', 'ticket.release', 'booking.view'] as $perm) {
            DB::statement('INSERT IGNORE INTO role_permission (role_id, permission_id) SELECT ?, id FROM permission WHERE code = ?', [$attendant->id, $perm]);
        }
        $rows = [];
        foreach (['entrance1' => ['S-0101', 'Sports', 'Entrance', 'SPORTS_ARENA'], 'store1' => ['S-0102', 'Sports', 'Store', 'SPORTS_STORE'], 'pool1' => ['S-0103', 'Pool', 'Gate', 'POOL_AREA']] as $username => [$number, $first, $last, $facCode]) {
            $staff = Staff::query()->updateOrCreate(['id' => DemoIds::staff($username)], [
                'organization_id' => DemoIds::org(), 'site_id' => DemoIds::site(), 'staff_number' => $number, 'first_name' => $first, 'last_name' => $last,
                'email' => $username.'@demo.007resort.test', 'is_active' => 1, 'deleted_at' => null,
            ]);
            $account = UserAccount::query()->updateOrCreate(['staff_id' => $staff->id], ['username' => $username, 'is_active' => 1, 'failed_login_count' => 0, 'locked_until' => null]);
            foreach ([Credential::PASSWORD => IdentityDemoSeeder::PASSWORD, Credential::PIN => IdentityDemoSeeder::PIN] as $type => $secret) {
                Credential::query()->where('user_account_id', $account->id)->where('credential_type', $type)->update(['is_active' => 0]);
                Credential::create(['user_account_id' => $account->id, 'credential_type' => $type, 'credential_hash' => Hash::make($secret), 'algorithm' => 'ARGON2ID']);
            }
            RoleAssignment::query()->updateOrCreate(['id' => DemoIds::of("ra:{$username}:SPORTS_ATTENDANT:{$facCode}")], [
                'staff_id' => $staff->id, 'role_id' => $attendant->id, 'scope_level' => 'FACILITY_UNIT', 'organization_id' => DemoIds::org(), 'site_id' => DemoIds::site(),
                'facility_unit_id' => DemoIds::facility($facCode), 'is_active' => 1, 'deleted_at' => null, 'granted_at' => now('UTC'),
            ]);
            $rows[] = [$username, $number, 'SPORTS_ATTENDANT', $facCode, IdentityDemoSeeder::PIN, IdentityDemoSeeder::PASSWORD];
        }
        $ctx->table('Booking demo logins (DEV-ONLY). Also: cashier1 (Reception: hold/confirm/issue), storekeeper1 (Sports Store: release/return), supervisor1/manager1 (everything)', ['username', 'staff no.', 'role', 'facility', 'PIN', 'password'], $rows);
        $ctx->table('Sample QR entitlements (arena + rental items at the Sports Store; pool ticket)', ['entitlement'], array_map(fn ($s) => [$s], $data->samples));
    }

    /**
     * Reception takes payment BEFORE service (a counter): Orders' default is pay-after-service, under which Payments refuses to
     * take money for a DRAFT order — that would block the whole Reception flow (slot fee + rentals paid at the counter).
     */
    private function receptionPaysFirst(string $receptionId): void
    {
        $bin = Ids::toBinary($receptionId);
        $cap = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('capability_code', 'POS')->value('id');
        if ($cap === null) {
            return;
        }
        DB::table('operating_rule')->updateOrInsert(['facility_capability_id' => $cap, 'rule_key' => 'payment_timing'], ['id' => Ids::toBinary(Ids::uuid7()), 'rule_value' => 'PAY_FIRST']);
    }
}
