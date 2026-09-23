<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\UserAccount;
use App\Domain\Identity\Services\StaffAuthService;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
    }

    private function login(string $user, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/staff/login', ['username' => $user] + ($body ?: ['password' => TestData::PASSWORD]));
    }

    public function test_password_login_succeeds_and_stores_only_hashes(): void
    {
        $staff = TestData::staff($this->t, 'wait1');
        $r = $this->login('wait1')->assertOk()->assertJsonStructure([
            'accessToken', 'refreshToken', 'expiresInSeconds', 'staff' => ['id', 'displayName', 'staffNumber', 'roles', 'permissions', 'facilityIds'],
            'session' => ['id', 'expiresAt', 'deviceId'],
        ]);
        $this->assertSame($staff->id, $r->json('staff.id'));
        $this->assertGreaterThan(800, $r->json('expiresInSeconds'));
        $r->json()['sessionId'] = $r->json('session.id');

        $row = DB::table('session')->where('id', Ids::toBinary($r->json('session.id')))->first();
        $this->assertSame(hash('sha256', $r->json('refreshToken')), $row->refresh_token_hash);
        $this->assertSame(hash('sha256', $r->json('accessToken')), $row->access_token_hash);
        $this->assertStringNotContainsString($r->json('refreshToken'), json_encode($row));
        $this->assertStringNotContainsString($r->json('accessToken'), json_encode($row));

        $hash = DB::table('credential')->where('credential_type', 'PASSWORD')->value('credential_hash');
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'staff.login')->count(), 'login writes an audit row');
    }

    public function test_legacy_username_password_shape_still_works_and_staff_number_identifies(): void
    {
        TestData::staff($this->t, 'legacy');
        $this->postJson('/api/v1/auth/staff/login', ['username' => 'legacy', 'password' => TestData::PASSWORD])->assertOk()->assertJsonPath('staff.displayName', 'Legacy Tester');
        $this->postJson('/api/v1/auth/staff/login', ['username' => 'legacy', 'pin' => TestData::PIN])->assertOk();
        $this->login('LEGACY', ['credentialType' => 'PIN', 'identifier' => 'LEGACY', 'secret' => TestData::PIN])->assertOk(); // staff_number (uppercased username)
    }

    public function test_nfc_card_requires_the_pin_and_never_works_alone(): void
    {
        $staff = TestData::staff($this->t, 'nfcuser');
        $account = UserAccount::query()->where('staff_id', $staff->id)->first();
        Credential::create([
            'user_account_id' => $account->id, 'credential_type' => 'NFC_CARD', 'algorithm' => 'SHA256',
            'credential_hash' => StaffAuthService::cardHash('04:A1:B2:C3'),
        ]);
        $this->login('x', ['credentialType' => 'NFC_CARD', 'identifier' => '04a1b2c3', 'secret' => TestData::PIN])->assertOk()->assertJsonPath('staff.id', $staff->id);
        $this->login('x', ['credentialType' => 'NFC_CARD', 'identifier' => '04a1b2c3', 'secret' => 'wrong'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $this->login('x', ['credentialType' => 'NFC_CARD', 'identifier' => 'deadbeef', 'secret' => TestData::PIN])->assertStatus(401);
        $this->assertGreaterThan(0, DB::table('user_account')->where('staff_id', Ids::toBinary($staff->id))->value('failed_login_count'));
    }

    public function test_pin_login_succeeds_and_wrong_pin_fails(): void
    {
        TestData::staff($this->t, 'cashier1');
        $this->login('cashier1', ['pin' => TestData::PIN])->assertOk();
        $this->assertStringStartsWith('$argon2id$', DB::table('credential')->where('credential_type', 'PIN')->value('credential_hash'));
        $this->login('cashier1', ['pin' => '0000'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_failure_is_401_problem_json_and_indistinguishable_for_unknown_user(): void
    {
        TestData::staff($this->t, 'known');
        $bad = $this->login('known', ['password' => 'wrong']);
        $unknown = $this->login('ghost', ['password' => 'wrong']);
        $bad->assertStatus(401)->assertJsonPath('code', 'invalid_credentials')->assertHeader('Content-Type', 'application/problem+json');
        $this->assertSame($bad->json('detail'), $unknown->json('detail'));
        $this->assertSame(1, DB::table('user_account')->where('username', 'known')->value('failed_login_count'));
        $this->assertGreaterThanOrEqual(2, DB::table('security_event')->where('event_type', 'LOGIN_FAILURE')->count());
    }

    public function test_validation_error_shape(): void
    {
        $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PASSWORD'])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.identifier.0', 'The identifier field is required.')
            ->assertJsonStructure(['correlationId']);
    }

    public function test_account_locks_after_five_failures_even_for_correct_password(): void
    {
        TestData::staff($this->t, 'locky');
        for ($i = 0; $i < 5; $i++) {
            $this->login('locky', ['password' => 'nope'])->assertStatus(401);
        }
        $this->login('locky')->assertStatus(403)->assertJsonPath('code', 'account_locked');
        $this->assertNotNull(DB::table('user_account')->where('username', 'locky')->value('locked_until'));

        // lock expires -> login works again and counter resets
        DB::table('user_account')->where('username', 'locky')->update(['locked_until' => now('UTC')->subMinute()]);
        $this->login('locky')->assertOk();
        $this->assertSame(0, DB::table('user_account')->where('username', 'locky')->value('failed_login_count'));
    }

    public function test_inactive_staff_cannot_login(): void
    {
        TestData::staff($this->t, 'gone', active: false);
        $this->login('gone')->assertStatus(403)->assertJsonPath('code', 'account_locked');
    }

    public function test_login_is_rate_limited_via_redis(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->login('nobody', ['password' => 'x']);
        }
        $this->login('nobody', ['password' => 'x'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_me_returns_staff_and_effective_permissions(): void
    {
        $staff = TestData::staff($this->t, 'sup1');
        TestData::assign($staff, 'UNIT_SUPERVISOR', 'SITE');
        $token = $this->login('sup1')->json('accessToken');

        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        foreach (['/api/v1/me', '/api/v1/auth/me'] as $url) {
            $me = $this->withToken($token)->getJson($url)->assertOk();
            $me->assertJsonPath('staff.id', $staff->id)->assertJsonPath('username', 'sup1')->assertJsonPath('device', null)->assertJsonStructure(['session' => ['id', 'expiresAt']]);
            $this->assertContains('order.void.approve', $me->json('staff.permissions'));
            $this->assertNotContains('device.register', $me->json('staff.permissions'));
            $this->assertSame(['UNIT_SUPERVISOR'], $me->json('staff.roles'));
            $this->assertSame('UNIT_SUPERVISOR', $me->json('assignments.0.roleCode'));
        }
    }

    public function test_refresh_rotates_and_reuse_revokes_the_chain(): void
    {
        TestData::staff($this->t, 'rot');
        $first = $this->login('rot')->json();

        $second = $this->postJson('/api/v1/auth/staff/refresh', ['refreshToken' => $first['refreshToken']])->assertOk()->json();
        $this->assertNotSame($first['accessToken'], $second['accessToken']);
        $this->assertNotSame($first['refreshToken'], $second['refreshToken']);

        // old access token is dead, new works
        $this->withToken($first['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
        $this->withToken($second['accessToken'])->getJson('/api/v1/me')->assertOk();

        // replaying the ROTATED refresh token = theft signal -> whole chain revoked
        $this->postJson('/api/v1/auth/staff/refresh', ['refreshToken' => $first['refreshToken']])
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->assertDatabaseHasRefreshRevoked($second['session']['id'], 'refresh_token_reuse');
        $this->withToken($second['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'REFRESH_TOKEN_REUSE')->count());
    }

    private function assertDatabaseHasRefreshRevoked(string $sessionId, string $reason): void
    {
        $row = DB::table('session')->where('id', Ids::toBinary($sessionId))->first();
        $this->assertNotNull($row->revoked_at);
        $this->assertSame($reason, $row->revoked_reason);
    }

    public function test_garbage_and_expired_refresh_tokens_are_rejected(): void
    {
        TestData::staff($this->t, 'exp');
        $s = $this->login('exp')->json();
        $this->postJson('/api/v1/auth/staff/refresh', ['refreshToken' => 'r7r_garbage'])->assertStatus(401);
        DB::table('session')->where('id', Ids::toBinary($s['session']['id']))->update(['expires_at' => now('UTC')->subMinute()]);
        $this->postJson('/api/v1/auth/staff/refresh', ['refreshToken' => $s['refreshToken']])->assertStatus(401)->assertJsonPath('code', 'token_expired');
    }

    public function test_expired_access_token_is_rejected(): void
    {
        TestData::staff($this->t, 'shortlived');
        $s = $this->login('shortlived')->json();
        DB::table('session')->where('id', Ids::toBinary($s['session']['id']))->update(['access_expires_at' => now('UTC')->subSecond()]);
        $this->withToken($s['accessToken'])->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'token_expired');
    }

    public function test_logout_revokes_server_side(): void
    {
        TestData::staff($this->t, 'bye');
        $s = $this->login('bye')->json();
        $this->withToken($s['accessToken'])->postJson('/api/v1/auth/staff/logout')->assertNoContent();
        $this->withToken($s['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/staff/refresh', ['refreshToken' => $s['refreshToken']])->assertStatus(401);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'staff.logout')->count());
    }

    public function test_session_revocation_requires_permission_for_other_sessions_and_is_audited(): void
    {
        $victim = TestData::staff($this->t, 'victim');
        $plain = TestData::staff($this->t, 'plain');
        $admin = TestData::staff($this->t, 'itadmin');
        TestData::assign($admin, 'IT_ADMIN', 'SITE');

        $v = $this->login('victim')->json();
        $p = $this->login('plain')->json();
        $a = $this->login('itadmin')->json();

        // no permission -> 403
        $this->withToken($p['accessToken'])->withHeaders(['Idempotency-Key' => 'rev-key-'.uniqid()])->postJson("/api/v1/auth/sessions/{$v['session']['id']}/revoke")
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'session.revoke');
        $this->withToken($v['accessToken'])->getJson('/api/v1/me')->assertOk();

        // IT admin (has session.revoke) -> revoked immediately + audit row
        $this->withToken($a['accessToken'])->withHeaders(['Idempotency-Key' => 'rev-key-'.uniqid()])->postJson("/api/v1/auth/sessions/{$v['session']['id']}/revoke", ['reason' => 'lost tablet'])->assertNoContent();
        $this->withToken($v['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
        $audit = DB::table('audit_log')->where('action', 'session.revoke')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, Ids::fromBinary($audit->actor_staff_id));
        $this->assertSame($v['session']['id'], Ids::fromBinary($audit->entity_id));

        // own session: always allowed
        $this->withToken($p['accessToken'])->withHeaders(['Idempotency-Key' => 'rev-key-'.uniqid()])->postJson("/api/v1/auth/sessions/{$p['session']['id']}/revoke")->assertNoContent();
        // unknown session
        $this->withToken($a['accessToken'])->withHeaders(['Idempotency-Key' => 'rev-key-'.uniqid()])->postJson('/api/v1/auth/sessions/'.Ids::uuid7().'/revoke')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_supervisor_step_up_issues_single_use_token_bound_to_permission(): void
    {
        $waiter = TestData::staff($this->t, 'waiter');
        TestData::assign($waiter, 'WAIT_STAFF', 'SITE');
        $sup = TestData::staff($this->t, 'super');
        TestData::assign($sup, 'UNIT_SUPERVISOR', 'SITE');
        $w = $this->login('waiter')->json();
        $req = fn (array $over = []) => $this->withToken($w['accessToken'])->postJson('/api/v1/auth/staff/step-up', $over + [
            'credentialType' => 'PIN', 'identifier' => 'super', 'secret' => TestData::PIN, 'permission' => 'order.void.approve',
            'entityType' => 'Order', 'entityId' => Ids::uuid7(),
        ]);

        $req(['secret' => 'wrong'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $req(['identifier' => 'waiter'])->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'order.void.approve'); // approver lacks it
        $req(['permission' => 'nope.nope'])->assertStatus(422);

        $entity = Ids::uuid7();
        $ok = $req(['entityId' => $entity])->assertOk()->assertJsonPath('approver.id', $sup->id)->assertJsonPath('approver.displayName', 'Super Tester');
        $token = $ok->json('stepUpToken');
        $this->assertSame(300, $ok->json('expiresInSeconds'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'staff.step_up')->count());

        Route::middleware(['api', 'auth:staff', 'stepup:order.void.approve'])->post('api/v1/_test/void', fn () => ['approver' => RequestContext::approverId()]);
        $this->withToken($w['accessToken'])->postJson('/api/v1/_test/void')->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->withToken($w['accessToken'])->withHeaders(['X-Step-Up-Token' => $token])->postJson('/api/v1/_test/void')->assertOk()->assertJsonPath('approver', $sup->id);
        $this->withToken($w['accessToken'])->withHeaders(['X-Step-Up-Token' => $token])->postJson('/api/v1/_test/void')->assertStatus(403)->assertJsonPath('code', 'step_up_required'); // single use

        // token for a different permission / stolen by another operator is rejected
        $t2 = $req()->json('stepUpToken');
        Route::middleware(['api', 'auth:staff', 'stepup:order.discount.approve'])->post('api/v1/_test/discount', fn () => ['ok' => true]);
        $this->withToken($w['accessToken'])->withHeaders(['X-Step-Up-Token' => $t2])->postJson('/api/v1/_test/discount')->assertStatus(403);
    }

    public function test_logout_all_sessions_revokes_every_session_of_the_account(): void
    {
        TestData::staff($this->t, 'multi');
        $a = $this->login('multi')->json();
        $b = $this->login('multi')->json();
        $this->withToken($a['accessToken'])->postJson('/api/v1/auth/staff/logout', ['allSessions' => true])->assertNoContent();
        $this->withToken($b['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
        $this->assertSame(0, DB::table('session')->whereNull('revoked_at')->count());
    }

    public function test_deactivated_staff_loses_access_immediately(): void
    {
        $staff = TestData::staff($this->t, 'fired');
        $s = $this->login('fired')->json();
        $staff->update(['is_active' => false]);
        $this->withToken($s['accessToken'])->getJson('/api/v1/me')->assertStatus(401);
    }
}
