<?php

namespace Tests\Feature;

use Database\Seeders\AttendanceDemoSeeder;
use Database\Seeders\MembershipDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\MembersFixtures;
use Tests\Support\TestData;
use Tests\TestCase;

class DemoSeedersTest extends TestCase
{
    use MembersFixtures;

    public function test_membership_demo_seeder_is_idempotent_and_matches_facilities_by_code(): void
    {
        $this->bootTenant();
        $pool = TestData::facility($this->t, 'pool');
        TestData::facility($this->t, 'gym');

        $this->seed(MembershipDemoSeeder::class);
        $this->seed(MembershipDemoSeeder::class);

        $plans = DB::table('membership_plan')->orderBy('code')->get()->keyBy('code');
        $this->assertSame(['GOLD', 'POOL_PASS', 'SILVER'], $plans->keys()->all());
        $this->assertSame('150000.0000', $plans['GOLD']->price);
        $this->assertSame(1, (int) $plans['GOLD']->property_wide);
        $this->assertNull($plans['GOLD']->visit_limit);
        $this->assertSame(12, (int) $plans['SILVER']->visit_limit);
        $this->assertSame(0, (int) $plans['SILVER']->property_wide);
        $this->assertSame(2, DB::table('plan_coverage')->where('plan_id', $plans['SILVER']->id)->count(), 'pool + gym exist, restaurant does not');
        $this->assertSame(1, DB::table('plan_coverage')->where('plan_id', $plans['POOL_PASS']->id)->count());
        $this->assertSame(0, DB::table('plan_coverage')->where('plan_id', $plans['GOLD']->id)->count());
        $this->assertNotNull($pool);

        [, $tok] = $this->actor('mgr');
        $this->getJson('/api/v1/memberships/plans', ['Authorization' => 'Bearer '.$tok])->assertOk()->assertJsonCount(3, 'items');
    }

    public function test_seeder_without_matching_facilities_falls_back_to_property_wide(): void
    {
        $this->bootTenant();
        $this->seed(MembershipDemoSeeder::class);
        $this->assertSame(3, DB::table('membership_plan')->where('property_wide', 1)->count());
    }

    public function test_attendance_demo_seeder_registers_terminal_and_links_staff(): void
    {
        $this->bootTenant();
        TestData::staff($this->t, 'aaa');
        TestData::staff($this->t, 'bbb');
        $this->seed(AttendanceDemoSeeder::class);
        $this->seed(AttendanceDemoSeeder::class);
        $this->assertSame(1, DB::table('attendance_device')->count());
        $this->assertSame(2, DB::table('staff_biometric_link')->where('is_active', 1)->count());
        $this->artisan('r007:attendance:simulate', ['--serial' => 'ZK-DEMO-001', '--days' => 2, '--seed' => 1])->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('attendance_day')->count());
    }
}
