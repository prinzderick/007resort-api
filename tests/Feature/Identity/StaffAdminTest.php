<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Role;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoIds;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoApi;
use Tests\TestCase;

class StaffAdminTest extends TestCase
{
    use DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_staff_list_filters_and_permission(): void
    {
        $this->api('cashier1')->get('/staff')->assertStatus(403)->assertJsonPath('permission', 'staff.manage');

        $m = $this->api('manager1');
        $all = $m->get('/staff?limit=5')->assertOk();
        $this->assertCount(5, $all->json('items'));
        $this->assertNotNull($all->json('nextCursor'));
        $this->assertSame('S-0001', $all->json('items.0.staffNumber'));
        $this->assertSame('ACTIVE', $all->json('items.0.status'));
        $this->assertSame(['S-0005'], array_column($m->get('/staff?q=ngozi')->json('items'), 'staffNumber'));
        $this->assertSame(['S-0005'], array_column($m->get('/staff?q=cashier1')->json('items'), 'staffNumber'), 'q also searches usernames');
        $this->assertSame([], $m->get('/staff?filter[status]=SUSPENDED')->json('items'));
        $this->assertSame(13, count($m->get('/staff?filter[status]=ACTIVE&limit=100')->json('items')));
    }

    public function test_create_update_suspend_are_audited_and_revoke_sessions(): void
    {
        $m = $this->api('manager1');
        $key = ['Idempotency-Key' => 'staff-create-000001'];
        $body = ['staffNumber' => 'S-0100', 'firstName' => 'Zainab', 'lastName' => 'Musa', 'email' => 'zainab@example.test', 'phone' => '+2348000000000'];

        $created = $m->post('/staff', $body, $key, false)->assertStatus(201)->assertHeader('ETag');
        $id = $created->json('id');
        $this->assertSame('ACTIVE', $created->json('status'));
        $this->assertFalse($created->json('hasPin'));
        // replay = same response, one row
        $again = $m->post('/staff', $body, $key, false)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($id, $again->json('id'));
        $this->assertSame(1, DB::table('staff')->where('staff_number', 'S-0100')->count());
        // a different key with the same staff number is a conflict, not a duplicate
        $m->post('/staff', $body)->assertStatus(409);
        $m->post('/staff', ['staffNumber' => 'S-0101'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'staff.create')->count());

        // give her a PIN + role so she can log in and hold a session
        $m->put("/staff/{$id}/credentials/pin", ['pin' => '5678'])->assertNoContent();
        $m->post("/staff/{$id}/role-assignments", ['roleId' => $this->roleId('WAIT_STAFF'), 'scopeType' => 'FACILITY', 'scopeId' => $this->facilityId('RESTAURANT')])->assertStatus(201);
        $z = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'S-0100', 'secret' => '5678'])->assertOk()->json();
        $this->withToken($z['accessToken'])->getJson('/api/v1/me')->assertOk();

        // PATCH needs If-Match; stale = 412
        $m->patch("/staff/{$id}", ['lastName' => 'Musa-Bello'])->assertStatus(428);
        $m->patch("/staff/{$id}", ['lastName' => 'Musa-Bello'], ['If-Match' => '"9"'])->assertStatus(412)->assertJsonPath('code', 'concurrency_conflict');
        $upd = $m->patch("/staff/{$id}", ['lastName' => 'Musa-Bello', 'phone' => null], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame('Zainab Musa-Bello', $upd->json('displayName'));
        $this->assertSame(2, $upd->json('rowVersion'));

        // suspension kills her session immediately and blocks login
        $m->patch("/staff/{$id}", ['status' => 'SUSPENDED'], ['If-Match' => '"2"'])->assertOk()->assertJsonPath('status', 'SUSPENDED');
        $this->withToken($z['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'S-0100', 'secret' => '5678'])->assertStatus(403)->assertJsonPath('code', 'account_locked');
        $m->patch("/staff/{$id}", ['status' => 'ACTIVE'], ['If-Match' => '"3"'])->assertOk();
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'S-0100', 'secret' => '5678'])->assertOk();

        // cannot suspend yourself
        $me = $this->api('manager1')->get('/auth/me')->json('staff.id');
        $ver = $m->get("/staff/{$me}")->json('rowVersion');
        $m->patch("/staff/{$me}", ['status' => 'SUSPENDED'], ['If-Match' => '"'.$ver.'"'])->assertStatus(409);

        $this->assertSame(3, DB::table('audit_log')->where('action', 'staff.update')->count());
        $this->assertTrue(Audit::verifyChain()->valid);
        $this->assertGreaterThanOrEqual(3, DB::table('outbox_event')->where('event_type', 'StaffRosterUpdated')->count());
    }

    public function test_credentials_are_hashed_audited_without_secrets_and_reset_lockout(): void
    {
        $m = $this->api('manager1');
        $staff = DemoIds::staff('cashier1');

        // lock cashier1 out first
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'cashier1', 'secret' => '0000']);
        }
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'cashier1', 'secret' => '1234'])->assertStatus(403)->assertJsonPath('code', 'account_locked');
        $live = $this->loginAs('wait1');

        $m->put("/staff/{$staff}/credentials/pin", ['pin' => 'abcd'])->assertStatus(422);
        $m->put("/staff/{$staff}/credentials/pin", ['pin' => '90210'])->assertNoContent();
        $m->put("/staff/{$staff}/credentials/password", ['password' => 'short'])->assertStatus(422);
        $m->put("/staff/{$staff}/credentials/password", ['password' => 'A-New-Passw0rd'])->assertNoContent();

        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'cashier1', 'secret' => '1234'])->assertStatus(401); // old PIN dead
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'cashier1', 'secret' => '90210'])->assertOk(); // unlocked + new PIN works
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PASSWORD', 'identifier' => 'cashier1', 'secret' => 'A-New-Passw0rd'])->assertOk();

        $hashes = DB::table('credential')->whereIn('credential_type', ['PIN', 'PASSWORD'])->where('is_active', 1)->pluck('credential_hash');
        $this->assertTrue($hashes->every(fn ($h) => str_starts_with($h, '$argon2id$')));
        $audit = json_encode(DB::table('audit_log')->where('action', 'credential.set')->get());
        $this->assertStringNotContainsString('90210', $audit);
        $this->assertStringNotContainsString('A-New-Passw0rd', $audit);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'credential.set')->count());
        $this->assertNotNull($live); // an unrelated user's session is untouched
        $this->withToken($live['accessToken'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_nfc_card_registration_and_login_with_pin(): void
    {
        $m = $this->api('manager1');
        $waiter = DemoIds::staff('wait1');
        $cashier = DemoIds::staff('cashier1');

        $m->put("/staff/{$waiter}/credentials/nfc-card", ['cardUid' => '04:AA:BB:CC'])->assertNoContent();
        $m->put("/staff/{$waiter}/credentials/nfc-card", ['cardUid' => '04aabbcc'])->assertNoContent(); // same card, idempotent
        $m->put("/staff/{$cashier}/credentials/nfc-card", ['cardUid' => '04:aa:bb:cc'])->assertStatus(409); // card already belongs to wait1
        $m->put("/staff/{$waiter}/credentials/nfc-card", ['cardUid' => 'ab'])->assertStatus(422);
        $this->assertSame(1, DB::table('credential')->where('credential_type', 'NFC_CARD')->where('is_active', 1)->count());

        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'NFC_CARD', 'identifier' => '04:AA:BB:CC', 'secret' => '1234'])->assertOk()->assertJsonPath('staff.staffNumber', 'S-0001');
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'NFC_CARD', 'identifier' => '04:AA:BB:CC', 'secret' => '9999'])->assertStatus(401);

        $m->delete("/staff/{$waiter}/credentials/nfc-card", [], false)->assertNoContent();
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'NFC_CARD', 'identifier' => '04:AA:BB:CC', 'secret' => '1234'])->assertStatus(401);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'like', 'credential.nfc%')->where('new_value', 'like', '%04%aa%')->count());
    }

    public function test_manager_cannot_manage_more_privileged_staff(): void
    {
        $m = $this->api('manager1');
        $owner = DemoIds::staff('owner1');
        $it = DemoIds::staff('itadmin1');

        $m->put("/staff/{$owner}/credentials/pin", ['pin' => '1111'])->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $m->put("/staff/{$it}/credentials/password", ['password' => 'Hijack-Passw0rd'])->assertStatus(403); // IT_ADMIN holds device/config perms the manager lacks
        $ver = $m->get("/staff/{$owner}")->json('rowVersion');
        $m->patch("/staff/{$owner}", ['status' => 'SUSPENDED'], ['If-Match' => '"'.$ver.'"'])->assertStatus(403);
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'owner1', 'secret' => '1234'])->assertOk(); // untouched

        // ...but can reset an operational user (kitchen staff holds only operational permissions) and the owner can do anything
        $m->put('/staff/'.DemoIds::staff('kitchen1').'/credentials/pin', ['pin' => '2222'])->assertNoContent();
        $this->api('owner1')->put("/staff/{$it}/credentials/pin", ['pin' => '3333'])->assertNoContent();
    }

    private function roleId(string $code): string
    {
        return Role::publicIdFor($code);
    }
}
