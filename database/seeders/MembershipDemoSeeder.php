<?php

namespace Database\Seeders;

use App\Domain\Membership\Models\MembershipPlan;
use App\Domain\Membership\Models\PlanCoverage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo membership plans: Gold (property-wide, unlimited), Silver (pool + gym + restaurant, 12 visits), Pool Pass (pool only, 8 visits).
 * Idempotent (keyed by plan code). Facilities are matched by code; if none of a plan's facilities exist yet the plan falls back to
 * property-wide so the demo still validates anywhere.
 *
 *   php artisan db:seed --class=Database\\Seeders\\MembershipDemoSeeder
 */
class MembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        $org = Tenant::organizationId();
        if ($org === null) {
            $this->command?->error('No organization found: create the tenant first.');

            return;
        }

        $plans = [
            ['code' => 'GOLD', 'name' => 'Gold Monthly', 'description' => 'Unlimited access to every facility, 2 guests per visit, 15% member discount.',
                'price' => '150000.0000', 'duration_days' => 30, 'visit_limit' => null, 'guest_allowance' => 2, 'member_discount_percent' => '15.00',
                'booking_advance_days' => 7, 'grace_period_days' => 5, 'renewal_notice_days' => 7, 'facilities' => null,
                'booking_privileges' => ['priorityBooking' => true, 'extendedAdvanceDays' => 7]],
            ['code' => 'SILVER', 'name' => 'Silver Monthly', 'description' => '12 visits a month to the pool, gym and restaurant, 1 guest, 10% member discount.',
                'price' => '80000.0000', 'duration_days' => 30, 'visit_limit' => 12, 'guest_allowance' => 1, 'member_discount_percent' => '10.00',
                'booking_advance_days' => 3, 'grace_period_days' => 3, 'renewal_notice_days' => 7, 'facilities' => ['pool', 'gym', 'restaurant'],
                'booking_privileges' => ['priorityBooking' => false, 'extendedAdvanceDays' => 3]],
            ['code' => 'POOL_PASS', 'name' => 'Pool Pass', 'description' => '8 pool visits, no guests.',
                'price' => '25000.0000', 'duration_days' => 30, 'visit_limit' => 8, 'guest_allowance' => 0, 'member_discount_percent' => '0.00',
                'booking_advance_days' => 0, 'grace_period_days' => 0, 'renewal_notice_days' => 5, 'facilities' => ['pool'],
                'booking_privileges' => []],
        ];

        foreach ($plans as $p) {
            $codes = $p['facilities'];
            unset($p['facilities']);
            $ids = $codes === null ? [] : DB::table('facility_unit')->where('organization_id', Ids::toBinary($org))->whereIn('code', $codes)->pluck('id')->map(fn ($b) => Ids::fromBinary($b))->all();
            $wide = $codes === null || $ids === [];

            $plan = MembershipPlan::query()->where('organization_id', $org)->where('code', $p['code'])->first();
            $attrs = $p + ['currency' => 'NGN', 'property_wide' => $wide, 'is_active' => true];
            if ($plan === null) {
                $plan = MembershipPlan::create($attrs + ['organization_id' => $org]);
            } else {
                $plan->update($attrs + ['row_version' => $plan->row_version + 1]);
            }
            PlanCoverage::query()->where('plan_id', $plan->id)->delete();
            if (! $wide) {
                foreach ($ids as $fid) {
                    PlanCoverage::create(['plan_id' => $plan->id, 'facility_unit_id' => $fid]);
                }
            }
            $this->command?->info(sprintf('%-10s %-14s %s  %s', $p['code'], $p['name'], $p['price'], $wide ? 'property-wide' : count($ids).' facilities'));
        }
    }
}
