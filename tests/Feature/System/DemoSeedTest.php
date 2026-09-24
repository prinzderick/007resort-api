<?php

namespace Tests\Feature\System;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoApi;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use DemoApi;

    public function test_seed_is_idempotent_and_complete(): void
    {
        $this->assertSame(0, Artisan::call('r007:demo-seed'));
        $counts = fn () => [DB::table('facility_unit')->count(), DB::table('facility_capability')->count(), DB::table('operating_rule')->count(), DB::table('operating_point')->count(), DB::table('staff')->count(), DB::table('role_assignment')->count(), DB::table('device')->count(), DB::table('device_registration')->count(), DB::table('bookable_resource')->count()];
        $first = $counts();
        Artisan::call('r007:demo-seed');
        $this->assertSame($first, $counts(), 're-running changes nothing');

        $this->assertSame(26, $first[0]); // every Phase-1 facility incl. Sports Arena children
        $this->assertSame(13, $first[4]); // one user per role (+ extra attendants)
        $this->assertSame(33, $first[6]); // 10 POS + 4 KDS + 18 tablets + attendance terminal
        $this->assertSame(1, DB::table('organization')->count());
        $this->assertSame(1, DB::table('site')->count());
        $this->assertSame(DemoIds::site(), Ids::fromBinary(DB::table('site')->value('id')));
    }

    public function test_every_role_has_a_demo_user_who_can_log_in_with_the_documented_credentials(): void
    {
        $this->seedDemo();
        $roles = [];
        foreach (['wait1', 'bartender1', 'kitchen1', 'cashier1', 'storekeeper1', 'supervisor1', 'procurement1', 'accountant1', 'manager1', 'itadmin1', 'owner1'] as $u) {
            $auth = $this->loginAs($u);
            $roles = array_merge($roles, $auth['staff']['roles']);
            $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PASSWORD', 'identifier' => $u, 'secret' => 'Dev#Pass1234'])->assertOk();
        }
        // MARKETING (website editor, CMS migration) has no demo login on purpose: no new demo credentials.
        $this->assertEqualsCanonicalizing(array_values(array_diff(DB::table('role')->pluck('code')->all(), ['MARKETING'])), $roles, 'one demo user per seeded role');
        $this->assertSame(['WAIT_STAFF'], $this->loginAs('wait1')['staff']['roles']);
        $this->assertContains('order.void.approve', $this->loginAs('supervisor1')['staff']['permissions']);
        $this->assertNotContains('order.void.approve', $this->loginAs('cashier1')['staff']['permissions']);
        $this->assertGreaterThanOrEqual(42, count($this->loginAs('owner1')['staff']['permissions'])); // V0001 seeds 42; module migrations add more
        // staff-number login (contract example)
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'S-0005', 'secret' => '1234'])->assertOk()->assertJsonPath('staff.displayName', 'Ngozi Eze');
    }

    public function test_demo_devices_authenticate_with_their_documented_tokens(): void
    {
        $this->seedDemo();
        $s = $this->loginAs('itadmin1');
        foreach (['POS_RECEPTION_1', 'KDS_MAIN_KITCHEN', 'TABLET_SUPERVISOR_4', 'ATTENDANCE_MAIN_GATE'] as $code) {
            $this->withHeaders(['X-Device-Token' => $this->deviceToken($code)])->getJson('/api/v1/devices/'.DemoIds::device($code))->assertOk()->assertJsonPath('status', 'ACTIVE');
        }
        $this->assertSame(4, DB::table('device')->where('device_type', 'KDS')->count());
        $this->assertSame(18, DB::table('device')->where('device_type', 'TABLET')->count());
        $this->assertNotNull($s);
    }

    public function test_refuses_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('r007:demo-seed'));
        $this->assertSame(0, DB::table('staff')->count());
    }
}
