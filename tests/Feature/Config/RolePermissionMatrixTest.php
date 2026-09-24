<?php

namespace Tests\Feature\Config;

use App\Domain\Identity\Models\Role;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\Support\TestResponseBuilder;

class RolePermissionMatrixTest extends ConfigTestCase
{
    private function ver(string $publicId): string
    {
        return '"'.DB::table('role')->where('public_id', Ids::toBinary($publicId))->value('row_version').'"';
    }

    /** A staff member whose role bundles exactly the given permissions (role literally named "Manager"). */
    private function adminWith(array $perms): TestResponseBuilder
    {
        $role = TestData::customRole('CFG_ADM_'.bin2hex(random_bytes(3)), 'Manager', array_merge(['role_assignment.manage', 'role.manage'], $perms));
        $staff = TestData::staff(['org' => DemoIds::org(), 'site' => DemoIds::site()], 'roleadm'.bin2hex(random_bytes(2)), TestData::PASSWORD, '1234');
        TestData::assignRole($staff, $role, 'SITE');

        return new TestResponseBuilder($this, $this->loginFor($staff), null);
    }

    public function test_matrix_shape_and_grantable_flag(): void
    {
        $o = $this->owner();
        $cashier = Role::publicIdFor('CASHIER');
        $m = $o->get("/roles/{$cashier}/permissions")->assertOk()->assertHeader('ETag');
        $this->assertSame('CASHIER', $m->json('role.code'));
        $this->assertTrue($m->json('role.system'));
        $this->assertTrue($m->json('role.editable'));
        $items = collect($m->json('groups'))->flatMap(fn ($g) => $g['items']);
        $this->assertSame(DB::table('permission')->count(), $items->count(), 'every permission appears exactly once');
        $this->assertTrue($items->firstWhere('code', 'payment.take')['granted']);
        $this->assertFalse($items->firstWhere('code', 'order.void.approve')['granted']);
        $this->assertTrue($items->every(fn ($i) => $i['grantable']), 'the owner can grant everything');
        $this->assertContains('Payments', collect($m->json('groups'))->pluck('group')->all());
        $this->assertFalse($o->get('/roles/'.Role::publicIdFor('OWNER').'/permissions')->json('role.editable'));

        $lim = $this->adminWith(['payment.take']);
        $mm = $lim->get("/roles/{$cashier}/permissions")->assertOk();
        $it = collect($mm->json('groups'))->flatMap(fn ($g) => $g['items']);
        $this->assertTrue($it->firstWhere('code', 'payment.take')['grantable']);
        $this->assertFalse($it->firstWhere('code', 'order.void.approve')['grantable']);
        $this->api('cashier1')->get("/roles/{$cashier}/permissions")->assertStatus(403);
    }

    public function test_custom_role_lifecycle_with_audit_and_versioning(): void
    {
        $o = $this->owner();
        $r = $o->post('/roles', ['name' => 'Front Desk Lead', 'description' => 'Runs reception', 'permissions' => ['payment.take', 'ticket.issue']])->assertStatus(201);
        $id = $r->json('role.id');
        $this->assertSame('CUSTOM_FRONT_DESK_LEAD', $r->json('role.code'));
        $this->assertFalse($r->json('role.system'));
        $o->post('/roles', ['name' => 'Front Desk Lead'])->assertStatus(201)->assertJsonPath('role.code', 'CUSTOM_FRONT_DESK_LEAD_2');
        $o->post('/roles', ['name' => 'Bad', 'permissions' => ['nope.nope']])->assertStatus(422);

        $ver = fn () => $this->ver($id);
        $o->put("/roles/{$id}/permissions", ['permissions' => [['code' => 'payment.take']]], [])->assertStatus(428);
        $put = $o->put("/roles/{$id}/permissions", ['permissions' => [['code' => 'payment.take'], ['code' => 'refund.execute', 'requiresApproval' => true], ['code' => 'receipt.view']]], ['If-Match' => $ver()])->assertOk();
        $codes = collect($put->json('groups'))->flatMap(fn ($g) => $g['items'])->where('granted', true)->pluck('code')->sort()->values()->all();
        $this->assertSame(['payment.take', 'receipt.view', 'refund.execute'], $codes);
        $this->assertTrue(collect($put->json('groups'))->flatMap(fn ($g) => $g['items'])->firstWhere('code', 'refund.execute')['requiresApproval']);
        $a = $this->audit('config.role.permissions.set', $id)[0];
        $this->assertEqualsCanonicalizing(['refund.execute', 'receipt.view'], $a->new['added']);
        $this->assertSame(['ticket.issue'], $a->new['removed']);
        $o->put("/roles/{$id}/permissions", ['permissions' => []], ['If-Match' => '"1"'])->assertStatus(412);
        $o->put("/roles/{$id}/permissions", ['permissions' => [['code' => 'payment.take'], ['code' => 'payment.take']]], ['If-Match' => $ver()])->assertStatus(422);
        // no-op keeps version
        $before = $ver();
        $o->put("/roles/{$id}/permissions", ['permissions' => [['code' => 'payment.take'], ['code' => 'refund.execute', 'requiresApproval' => true], ['code' => 'receipt.view']]], ['If-Match' => $before])->assertOk()->assertHeader('ETag', $before);

        $o->patch("/roles/{$id}", ['name' => 'Front Desk Manager'], ['If-Match' => $ver()])->assertOk()->assertJsonPath('role.name', 'Front Desk Manager');
        $this->assertNotEmpty($this->outbox($id, 'rolePermissions'));

        // assign it, then it cannot be deleted
        $staff = DemoIds::staff('cashier1');
        $o->post("/staff/{$staff}/role-assignments", ['roleId' => $id, 'scopeType' => 'SITE', 'scopeId' => DemoIds::site()])->assertStatus(201);
        $o->delete("/roles/{$id}", idem: false)->assertStatus(409)->assertJsonPath('code', 'role_in_use');
        // a permission change is live immediately for holders of the role
        $this->assertTrue(DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->where('p.code', 'receipt.view')->exists());

        $free = $o->post('/roles', ['name' => 'Temp role'])->json('role.id');
        $o->delete("/roles/{$free}", idem: false)->assertNoContent();
        $this->assertSame(0, DB::table('role')->where('public_id', Ids::toBinary($free))->count());
        $this->assertNotEmpty($this->audit('config.role.delete', $free));
    }

    public function test_system_roles_and_owner_rules(): void
    {
        $o = $this->owner();
        $owner = Role::publicIdFor('OWNER');
        $o->put("/roles/{$owner}/permissions", ['permissions' => []], ['If-Match' => $this->ver($owner)])->assertStatus(403)->assertJsonPath('code', 'role_immutable');
        $cashier = Role::publicIdFor('CASHIER');
        $o->patch("/roles/{$cashier}", ['name' => 'Renamed'], ['If-Match' => $this->ver($cashier)])->assertStatus(403)->assertJsonPath('code', 'role_immutable');
        $o->delete("/roles/{$cashier}", idem: false)->assertStatus(403);
        // owner may edit a built-in role's permissions
        $perms = collect($o->get("/roles/{$cashier}/permissions")->json('groups'))->flatMap(fn ($g) => $g['items'])->where('granted', true)->pluck('code')->map(fn ($c) => ['code' => $c])->all();
        $o->put("/roles/{$cashier}/permissions", ['permissions' => $perms], ['If-Match' => $this->ver($cashier)])->assertOk();
        $this->assertSame(11, count($o->get('/roles?limit=200')->json('items')) - (int) DB::table('role')->where('is_system', 0)->count());
    }

    public function test_nobody_can_escalate_beyond_their_own_permissions(): void
    {
        $o = $this->owner();
        $custom = $o->post('/roles', ['name' => 'Escalation target'])->json('role.id');
        // an admin holding role.manage + payment.take only
        $adm = $this->adminWith(['payment.take']);
        $ok = $adm->put("/roles/{$custom}/permissions", ['permissions' => [['code' => 'payment.take']]], ['If-Match' => $this->ver($custom)])->assertOk();
        $this->assertNotNull($ok);
        // cannot add a permission they lack (refund.execute / config / role.manage-only stuff)
        $bad = $adm->put("/roles/{$custom}/permissions", ['permissions' => [['code' => 'payment.take'], ['code' => 'refund.execute']]], ['If-Match' => $this->ver($custom)])->assertStatus(403);
        $this->assertSame('privilege_escalation', $bad->json('code'));
        $this->assertSame(['refund.execute'], $bad->json('missingPermissions'));
        $this->assertNotContains('refund.execute', DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->join('role as r', 'r.id', '=', 'rp.role_id')->where('r.public_id', Ids::toBinary($custom))->pluck('p.code')->all());
        // cannot edit a role that already holds things above them (built-in Cashier has payment.take + others)
        $cashier = Role::publicIdFor('CASHIER');
        $adm->put("/roles/{$cashier}/permissions", ['permissions' => [['code' => 'payment.take']]], ['If-Match' => $this->ver($cashier)])->assertStatus(403)->assertJsonPath('code', 'privilege_escalation');
        // cannot create a role with more than they hold
        $adm->post('/roles', ['name' => 'Sneaky', 'permissions' => ['audit.view']])->assertStatus(403)->assertJsonPath('code', 'privilege_escalation');
        // and without role.manage there is no write at all
        $noRoleManage = $this->managerLacking(['role.manage']);
        $noRoleManage->put("/roles/{$custom}/permissions", ['permissions' => []], ['If-Match' => $this->ver($custom)])->assertStatus(403)->assertJsonPath('permission', 'role.manage');
        $this->api('manager1')->post('/roles', ['name' => 'Mgr role'])->assertStatus(403); // default MANAGER bundle does not include role.manage
    }
}
