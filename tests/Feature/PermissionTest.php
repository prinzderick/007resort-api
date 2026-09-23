<?php

namespace Tests\Feature;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

/** Parity with the ASP.NET reference: authorization is permission-based, never role-name-based. */
class PermissionTest extends TestCase
{
    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
    }

    private function token(string $user): string
    {
        return $this->postJson('/api/v1/auth/staff/login', ['username' => $user, 'password' => TestData::PASSWORD])->json('accessToken');
    }

    public function test_role_named_manager_without_the_permission_is_denied(): void
    {
        $staff = TestData::staff($this->t, 'fakemanager');
        // A role literally CALLED "Manager"/MANAGER-ish, bundling only an unrelated permission.
        $role = TestData::customRole('MANAGER_LOOKALIKE', 'Manager', ['order.create']);
        TestData::assignRole($staff, $role, 'ORGANIZATION');

        $this->withToken($this->token('fakemanager'))->getJson('/api/v1/_test/needs-device-register')
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'device.register');
    }

    public function test_holder_of_the_permission_is_allowed_regardless_of_role_name(): void
    {
        $staff = TestData::staff($this->t, 'sneaky');
        TestData::assignRole($staff, TestData::customRole('HOUSEKEEPER', 'Housekeeper', ['device.register']), 'SITE');

        $this->withToken($this->token('sneaky'))->getJson('/api/v1/_test/needs-device-register')->assertOk();
    }

    public function test_permission_granted_at_facility_scope_covers_that_subtree_only(): void
    {
        $restaurant = TestData::facility($this->t, 'restaurant');
        $counter = TestData::facility($this->t, 'counter', $restaurant->id);
        $poolBar = TestData::facility($this->t, 'pool-bar');

        $staff = TestData::staff($this->t, 'cashier1');
        TestData::assign($staff, 'CASHIER', 'FACILITY_UNIT', $restaurant->id);
        $token = $this->token('cashier1');

        $this->withToken($token)->getJson("/api/v1/_test/facilities/{$restaurant->id}/settle")->assertOk();
        $this->withToken($token)->getJson("/api/v1/_test/facilities/{$counter->id}/settle")->assertOk(); // descendant
        $this->withToken($token)->getJson("/api/v1/_test/facilities/{$poolBar->id}/settle")->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->withToken($token)->getJson('/api/v1/_test/needs-device-register')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/_test/facilities/'.Ids::uuid7().'/settle')->assertStatus(404);
    }

    public function test_site_and_organization_scope_and_inactive_assignments(): void
    {
        $f = TestData::facility($this->t, 'spa');
        $checker = app(PermissionChecker::class);

        $siteWide = TestData::staff($this->t, 'sitewide');
        $ra = TestData::assign($siteWide, 'CASHIER', 'SITE');
        $this->assertTrue($checker->can($siteWide->id, 'order.settle', Scope::facility($f->id)));
        $this->assertTrue($checker->can($siteWide->id, 'order.settle', Scope::site($this->t['site'])));
        $this->assertFalse($checker->can($siteWide->id, 'order.settle', Scope::organization($this->t['org'])));
        $this->assertFalse($checker->can($siteWide->id, 'device.register'));

        $ra->update(['is_active' => false]);
        $this->assertFalse($checker->can($siteWide->id, 'order.settle'));
        $ra->update(['is_active' => true, 'deleted_at' => now('UTC')]);
        $this->assertFalse($checker->can($siteWide->id, 'order.settle'));

        $owner = TestData::staff($this->t, 'owner1');
        TestData::assign($owner, 'OWNER', 'ORGANIZATION');
        $this->assertTrue($checker->can($owner->id, 'device.register', Scope::facility($f->id)));
        $this->assertTrue($checker->can($owner->id, 'audit.view', Scope::organization($this->t['org'])));
    }

    public function test_role_permission_bundles_match_the_matrix(): void
    {
        $checker = app(PermissionChecker::class);
        foreach ([
            ['WAIT_STAFF', 'order.send', true], ['WAIT_STAFF', 'payment.take', false], ['CASHIER', 'payment.take', true],
            ['CASHIER', 'order.void.approve', false], ['UNIT_SUPERVISOR', 'order.void.approve', true],
            ['IT_ADMIN', 'pricing.manage', false], ['IT_ADMIN', 'device.register', true], ['MANAGER', 'pricing.manage', true],
            ['ACCOUNTANT', 'refund.approve', true], ['KITCHEN_STAFF', 'prep_ticket.transition', true],
        ] as $i => [$role, $perm, $expected]) {
            $s = TestData::staff($this->t, "matrix{$i}");
            TestData::assign($s, $role, 'SITE');
            $this->assertSame($expected, $checker->can($s->id, $perm), "$role / $perm");
        }
    }

    public function test_role_permission_table_drives_the_decision(): void
    {
        $s = TestData::staff($this->t, 'dynamic');
        $role = TestData::customRole('DYN', 'Dyn', []);
        TestData::assignRole($s, $role, 'SITE');
        $checker = app(PermissionChecker::class);
        $this->assertFalse($checker->can($s->id, 'device.view'));
        DB::table('role_permission')->insert(['role_id' => $role->id, 'permission_id' => DB::table('permission')->where('code', 'device.view')->value('id'), 'requires_approval' => 1]);
        $grant = $checker->grant($s->id, 'device.view');
        $this->assertNotNull($grant);
        $this->assertTrue($grant->requiresApproval);
    }
}
