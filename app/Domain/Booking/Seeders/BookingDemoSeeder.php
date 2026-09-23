<?php

namespace App\Domain\Booking\Seeders;

use App\Domain\Booking\Models\AvailabilitySchedule;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Models\BookingRule;
use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Domain\Organization\Models\FacilityUnit;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Site;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\EntitlementService;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Demo data for Sports Booking / Ticketing (Reception, Sports Entrance, Sports Store, Pool). Idempotent.
 *
 * Facilities:  RECEPTION, SPORTS-ARENA > SPORTS-ENTRANCE, SPORTS-STORE, POOL.
 *  - ACCESS items of arena bookings are bound to SPORTS-ARENA and are valid at SPORTS-ENTRANCE (its child).
 * Resources:   Football Pitch (whole, hourly), Lawn Tennis Court 1/2 (hourly), Basketball Court (hourly),
 *              Tennis Clinic (per-seat capacity 8, whole-clinic allowed, 2 seats reserved for offline Reception).
 * Ticketing:   POOL-ADULT / POOL-CHILD ticket types (individual, single-use, valid the issue day).
 * Demo people: users reception / entrance / store / pool  (dev password below — NEVER use outside dev).
 * Catalog:     (if the Catalog tables exist) slot-fee, pool-ticket, rental and store-goods products, linked to the above.
 * Samples:     two ready-made entitlements (arena booking + racket rental; pool ticket) whose QR tokens are printed.
 */
class BookingDemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Pass-007!';

    public const PIN = '2468';

    public function run(): void
    {
        DB::transaction(function () {
            $site = Site::query()->orderBy('created_at')->first();
            if ($site === null) {
                $org = Organization::create(['name' => '007 Resort & Spa']);
                $site = Site::create(['organization_id' => $org->id, 'name' => '007 Resort & Spa']);
            }
            $t = ['org' => $site->organization_id, 'site' => $site->id];

            $reception = $this->facility($t, 'RECEPTION', 'Main Reception');
            $arena = $this->facility($t, 'SPORTS-ARENA', 'Sports Arena');
            $entrance = $this->facility($t, 'SPORTS-ENTRANCE', 'Sports Entrance', $arena->id);
            $store = $this->facility($t, 'SPORTS-STORE', 'Sports Store');
            $pool = $this->facility($t, 'POOL', 'Pool');

            foreach ([
                ['FOOTBALL', 'Football Pitch', BookableResource::MODE_WHOLE, 1, '25000.0000'],
                ['TENNIS-1', 'Lawn Tennis Court 1', BookableResource::MODE_SLOT, 1, '5000.0000'],
                ['TENNIS-2', 'Lawn Tennis Court 2', BookableResource::MODE_SLOT, 1, '5000.0000'],
                ['BASKETBALL', 'Basketball Court', BookableResource::MODE_SLOT, 1, '8000.0000'],
                ['TENNIS-CLINIC', 'Tennis Clinic', BookableResource::MODE_CAPACITY, 8, '3000.0000'],
            ] as [$code, $name, $mode, $cap, $price]) {
                $r = BookableResource::query()->where('site_id', $site->id)->where('code', $code)->first() ?? BookableResource::create([
                    'organization_id' => $t['org'], 'site_id' => $t['site'], 'facility_unit_id' => $arena->id, 'code' => $code, 'name' => $name, 'mode' => $mode,
                    'capacity' => $cap, 'slot_minutes' => 60, 'max_slots_per_booking' => 4, 'price' => $price,
                    'allow_whole_resource' => $mode === BookableResource::MODE_CAPACITY, 'local_reserve_units' => $mode === BookableResource::MODE_CAPACITY ? 2 : 0,
                ]);
                if (AvailabilitySchedule::query()->where('resource_id', $r->id)->doesntExist()) {
                    foreach (range(1, 7) as $dow) {
                        AvailabilitySchedule::create(['resource_id' => $r->id, 'day_of_week' => $dow, 'open_time' => '07:00:00', 'close_time' => '21:00:00']);
                    }
                }
            }
            if (BookingRule::query()->where('facility_unit_id', $arena->id)->doesntExist()) {
                BookingRule::create(['organization_id' => $t['org'], 'facility_unit_id' => $arena->id, 'hold_ttl_seconds' => 600, 'cancel_cutoff_minutes' => 120, 'reschedule_cutoff_minutes' => 120, 'max_reschedules' => 2, 'early_entry_minutes' => 15]);
            }

            foreach ([['POOL-ADULT', 'Pool - Adult'], ['POOL-CHILD', 'Pool - Child']] as [$code, $name]) {
                TicketType::query()->where('site_id', $site->id)->where('code', $code)->first() ?? TicketType::create([
                    'organization_id' => $t['org'], 'site_id' => $t['site'], 'facility_unit_id' => $pool->id, 'code' => $code, 'name' => $name,
                    'format' => 'INDIVIDUAL', 'validation_mode' => 'SINGLE_USE', 'validity_kind' => 'ISSUE_DAY',
                ]);
            }

            // People (dev credentials). Roles: existing CASHIER for Reception; a facility-scoped attendant role for scanners/store.
            $attendant = Role::query()->where('code', 'SPORTS_ATTENDANT')->first() ?? Role::create(['code' => 'SPORTS_ATTENDANT', 'name' => 'Sports / pool attendant', 'description' => 'Scans tickets and releases/returns rental items']);
            foreach (['ticket.view', 'ticket.redeem', 'ticket.release', 'booking.view'] as $perm) {
                DB::statement('INSERT IGNORE INTO role_permission (role_id, permission_id) SELECT ?, id FROM permission WHERE code = ?', [$attendant->id, $perm]);
            }
            $cashier = Role::query()->where('code', 'CASHIER')->firstOrFail();
            $this->person($t, 'reception', $cashier, $reception->id);
            $this->person($t, 'entrance', $attendant, $entrance->id);
            $this->person($t, 'store', $attendant, $store->id);
            $this->person($t, 'pool', $attendant, $pool->id);

            $this->catalog($t, $reception, $store);
            $this->samples($t, $arena, $store, $pool);
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

    /**
     * Sellable products for the Reception flow (only when the Catalog tables exist): slot fees (one per resource, linked via
     * bookable_resource.product_id), pool tickets (linked via ticket_type.product_id), rentals and store goods.
     */
    private function catalog(array $t, FacilityUnit $reception, FacilityUnit $store): void
    {
        if (! Schema::hasTable('product') || ! Schema::hasTable('price_list')) {
            return;
        }
        $org = Ids::toBinary($t['org']);
        $cat = DB::table('product_category')->where('organization_id', $org)->where('name', 'Sports & Pool')->value('id') ?? tap(Ids::toBinary(Ids::uuid7()), fn ($id) => DB::table('product_category')->insert(['id' => $id, 'organization_id' => $org, 'name' => 'Sports & Pool', 'sort_order' => 90]));
        $list = DB::table('price_list')->where('organization_id', $org)->where('is_default', 1)->value('id') ?? tap(Ids::toBinary(Ids::uuid7()), fn ($id) => DB::table('price_list')->insert(['id' => $id, 'organization_id' => $org, 'name' => 'Standard', 'is_default' => 1]));

        $sell = function (string $sku, string $name, string $kind, string $price, bool $atStore = false) use ($org, $cat, $list, $reception, $store): string {
            $id = DB::table('product')->where('organization_id', $org)->where('sku', $sku)->value('id');
            if ($id === null) {
                $id = Ids::toBinary(Ids::uuid7());
                DB::table('product')->insert(['id' => $id, 'organization_id' => $org, 'category_id' => $cat, 'sku' => $sku, 'name' => $name, 'kind' => $kind]);
                DB::table('price')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'price_list_id' => $list, 'product_id' => $id, 'amount' => $price]);
            }
            foreach (array_filter([$reception->id, $atStore ? $store->id : null]) as $fac) {
                DB::table('product_facility')->insertOrIgnore(['product_id' => $id, 'facility_unit_id' => Ids::toBinary($fac)]);
            }

            return Ids::fromBinary($id);
        };

        foreach (['FOOTBALL' => ['FEE-FOOTBALL', 'Football pitch (per hour)', '25000'], 'TENNIS-1' => ['FEE-TENNIS-1', 'Lawn tennis court 1 (per hour)', '5000'], 'TENNIS-2' => ['FEE-TENNIS-2', 'Lawn tennis court 2 (per hour)', '5000'],
            'BASKETBALL' => ['FEE-BASKETBALL', 'Basketball court (per hour)', '8000'], 'TENNIS-CLINIC' => ['FEE-TENNIS-CLINIC', 'Tennis clinic (per seat)', '3000']] as $code => [$sku, $name, $price]) {
            DB::table('bookable_resource')->where('site_id', Ids::toBinary($t['site']))->where('code', $code)->update(['product_id' => Ids::toBinary($sell($sku, $name, 'FEE', $price))]);
        }
        foreach (['POOL-ADULT' => ['Pool - Adult', '3000'], 'POOL-CHILD' => ['Pool - Child', '1500']] as $code => [$name, $price]) {
            DB::table('ticket_type')->where('site_id', Ids::toBinary($t['site']))->where('code', $code)->update(['product_id' => Ids::toBinary($sell($code, $name, 'TICKET', $price))]);
        }
        $sell('RENTAL-RACKET', 'Tennis racket hire', 'RENTAL', '1500');
        $sell('RENTAL-FOOTBALL', 'Football hire', 'RENTAL', '1000');
        $sell('RENTAL-BASKETBALL', 'Basketball hire', 'RENTAL', '800');
        $sell('GOODS-TENNIS-BALLS', 'Tennis balls (tube)', 'GOOD', '2500', atStore: true);
        $sell('GOODS-SPORTS-DRINK', 'Sports drink', 'GOOD', '700', atStore: true);
    }

    /** Ready-made entitlements so Entrance/Store can be demoed before the Reception order flow exists. */
    private function samples(array $t, FacilityUnit $arena, FacilityUnit $store, FacilityUnit $pool): void
    {
        $svc = app(EntitlementService::class);
        $now = CarbonImmutable::now('UTC');
        if (Entitlement::query()->where('source_key', 'demo:arena-rental')->doesntExist()) {
            $svc->issue('demo:arena-rental', $t['org'], $t['site'], [
                ['kind' => 'ACCESS', 'name' => 'Lawn Tennis Court 1 - Demo', 'qty' => 1, 'facilityUnitId' => $arena->id, 'validationMode' => 'SINGLE_USE', 'validFrom' => $now->subHour(), 'validUntil' => $now->addDays(30)],
                ['kind' => 'RENTAL', 'name' => 'Racket x2', 'qty' => 2, 'facilityUnitId' => $store->id, 'validationMode' => 'NONE'],
                ['kind' => 'GOODS', 'name' => 'Tennis balls (tube)', 'qty' => 1, 'facilityUnitId' => $store->id, 'validationMode' => 'NONE'],
            ], holderName: 'Demo Guest');
        }
        if (Entitlement::query()->where('source_key', 'demo:pool-adult')->doesntExist()) {
            $type = TicketType::query()->where('site_id', $t['site'])->where('code', 'POOL-ADULT')->first();
            $svc->issue('demo:pool-adult', $t['org'], $t['site'], [
                ['kind' => 'ACCESS', 'name' => 'Pool - Adult', 'qty' => 1, 'facilityUnitId' => $pool->id, 'ticketTypeId' => $type?->id, 'validationMode' => 'SINGLE_USE', 'validFrom' => $now->subHour(), 'validUntil' => $now->addDays(30)],
            ], holderName: 'Demo Swimmer');
        }
        foreach (['demo:arena-rental' => 'arena+rental', 'demo:pool-adult' => 'pool'] as $key => $label) {
            $e = Entitlement::query()->where('source_key', $key)->first();
            $this->command?->line("  sample {$label} entitlement {$e->id}  qrToken={$e->qr_token}");
        }
    }
}
