<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Role;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoApi;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function role(string $code): string
    {
        return Role::publicIdFor($code);
    }

    public function test_roles_and_permissions_catalog(): void
    {
        $m = $this->api('manager1');
        $roles = $m->get('/roles?limit=200')->assertOk()->json('items');
        $this->assertCount(11, $roles);
        $cashier = collect($roles)->firstWhere('code', 'CASHIER');
        $this->assertSame(Role::publicIdFor('CASHIER'), $cashier['id']);
        $this->assertTrue(Ids::isUuid($cashier['id']));
        $this->assertContains('payment.take', $cashier['permissions']);
        $this->assertNotContains('order.void.approve', $cashier['permissions']);
        $this->assertCount(42, collect($roles)->firstWhere('code', 'OWNER')['permissions']);

        $page = $m->get('/roles?limit=4')->json();
        $this->assertCount(4, $page['items']);
        $next = $m->get('/roles?limit=4&cursor='.$page['nextCursor'])->json('items');
        $this->assertEmpty(array_intersect(array_column($page['items'], 'code'), array_column($next, 'code')));

        $perms = $m->get('/permissions')->assertOk()->json('items');
        $this->assertCount(42, $perms);
        $this->assertContains('order.void.approve', array_column($perms, 'code'));

        $this->api('cashier1')->get('/roles')->assertStatus(403)->assertJsonPath('permission', 'role_assignment.manage');
    }

    public function test_grant_is_idempotent_audited_and_takes_effect_immediately(): void
    {
        $m = $this->api('manager1');
        $target = DemoIds::staff('wait2');
        $facility = $this->facilityId('CAFE');
        $body = ['roleId' => $this->role('CASHIER'), 'scopeType' => 'FACILITY', 'scopeId' => $facility];
        $wait2 = $this->loginAs('wait2');
        $this->assertNotContains('payment.take', $wait2['staff']['permissions']);

        $first = $m->post("/staff/{$target}/role-assignments", $body, ['Idempotency-Key' => 'grant-key-000000001'], false)->assertStatus(201);
        $this->assertSame('FACILITY', $first->json('scopeType'));
        $this->assertSame($facility, $first->json('scopeId'));
        $this->assertSame($this->api('manager1')->auth['staff']['id'], $first->json('grantedByStaffId'));
        $this->assertNull($first->json('revokedAt'));

        // same key: replay of the original response
        $replay = $m->post("/staff/{$target}/role-assignments", $body, ['Idempotency-Key' => 'grant-key-000000001'], false)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('id'), $replay->json('id'));
        // new key, same grant: converges on the existing assignment (200, no duplicate row)
        $again = $m->post("/staff/{$target}/role-assignments", $body)->assertOk();
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, DB::table('role_assignment')->where('staff_id', Ids::toBinary($target))->where('role_id', Role::query()->where('code', 'CASHIER')->value('id'))->where('is_active', 1)->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'role_assignment.grant')->count());

        // effective immediately (no re-login), scoped to that facility only
        $me = $this->withToken($wait2['accessToken'])->getJson('/api/v1/auth/me')->json();
        $this->assertContains('payment.take', $me['staff']['permissions']);
        $this->assertContains($facility, $me['staff']['facilityIds']);
        $this->assertSame('FACILITY_UNIT', collect($me['grants'])->firstWhere('permission', 'payment.take')['scopeLevel']);

        // list shows it
        $list = $m->get("/staff/{$target}/role-assignments")->assertOk()->json('items');
        $this->assertContains($first->json('id'), array_column($list, 'id'));

        // revoke (idempotent) + audit + effective immediately
        $m->delete("/staff/{$target}/role-assignments/".$first->json('id'))->assertNoContent();
        $m->delete("/staff/{$target}/role-assignments/".$first->json('id'))->assertNoContent();
        $this->assertSame(1, DB::table('audit_log')->where('action', 'role_assignment.revoke')->count());
        $this->assertNotContains('payment.take', $this->withToken($wait2['accessToken'])->getJson('/api/v1/auth/me')->json('staff.permissions'));
        $revoked = collect($m->get("/staff/{$target}/role-assignments")->json('items'))->firstWhere('id', $first->json('id'));
        $this->assertNotNull($revoked['revokedAt']);

        // re-granting after revoke creates a new active assignment
        $m->post("/staff/{$target}/role-assignments", $body)->assertStatus(201);
        $this->assertTrue(Audit::verifyChain()->valid);
        $this->assertSame(3, DB::table('outbox_event')->where('event_type', 'StaffRosterUpdated')->where('entity_type', 'RoleAssignment')->count());
        $m->delete("/staff/{$target}/role-assignments/".Ids::uuid7())->assertStatus(404);
    }

    public function test_anti_escalation_is_permission_based_not_role_name_based(): void
    {
        $m = $this->api('manager1');
        $cashier = DemoIds::staff('cashier1');
        $site = DemoIds::site();

        // Manager holds staff.manage/role_assignment.manage but NOT device.register -> cannot hand out IT_ADMIN, or OWNER
        $m->post("/staff/{$cashier}/role-assignments", ['roleId' => $this->role('IT_ADMIN'), 'scopeType' => 'SITE', 'scopeId' => $site])
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $m->post("/staff/{$cashier}/role-assignments", ['roleId' => $this->role('OWNER'), 'scopeType' => 'ORGANIZATION', 'scopeId' => DemoIds::org()])->assertStatus(403);
        $m->post("/staff/{$cashier}/role-assignments", ['roleId' => $this->role('ACCOUNTANT'), 'scopeType' => 'SITE', 'scopeId' => $site])->assertStatus(403); // finance perms not held by manager
        // ...but operational roles are fine even though the manager doesn't hold every operational permission (prep_ticket.*)
        $m->post("/staff/{$cashier}/role-assignments", ['roleId' => $this->role('KITCHEN_STAFF'), 'scopeType' => 'FACILITY', 'scopeId' => $this->facilityId('MAIN_KITCHEN')])->assertStatus(201);
        // owner can grant anything
        $this->api('owner1')->post("/staff/{$cashier}/role-assignments", ['roleId' => $this->role('IT_ADMIN'), 'scopeType' => 'SITE', 'scopeId' => $site])->assertStatus(201);

        // a custom role NAMED "Manager" without the permissions grants nothing special and cannot be used to escalate
        $fake = Role::create(['code' => 'FAKE_MANAGER', 'name' => 'Manager']);
        $this->api('supervisor1')->post("/staff/{$cashier}/role-assignments", ['roleId' => $fake->public_id, 'scopeType' => 'SITE', 'scopeId' => $site])->assertStatus(403)->assertJsonPath('permission', 'role_assignment.manage');
    }

    public function test_validation_and_not_found(): void
    {
        $m = $this->api('manager1');
        $t = DemoIds::staff('cashier1');
        $m->post("/staff/{$t}/role-assignments", [])->assertStatus(422)->assertJsonStructure(['errors' => ['roleId', 'scopeType', 'scopeId']]);
        $m->post("/staff/{$t}/role-assignments", ['roleId' => Ids::uuid7(), 'scopeType' => 'SITE', 'scopeId' => DemoIds::site()])->assertStatus(404);
        $m->post("/staff/{$t}/role-assignments", ['roleId' => $this->role('CASHIER'), 'scopeType' => 'FACILITY', 'scopeId' => Ids::uuid7()])->assertStatus(422)->assertJsonPath('errors.scopeId.0', 'Unknown facility.');
        $m->post('/staff/'.Ids::uuid7().'/role-assignments', ['roleId' => $this->role('CASHIER'), 'scopeType' => 'SITE', 'scopeId' => DemoIds::site()])->assertStatus(404);
        $m->post("/staff/{$t}/role-assignments", ['roleId' => $this->role('CASHIER'), 'scopeType' => 'GALAXY', 'scopeId' => DemoIds::site()])->assertStatus(422);
    }
}
