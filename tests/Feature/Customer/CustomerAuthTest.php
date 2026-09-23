<?php

namespace Tests\Feature\Customer;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerHelpers;
use Tests\Support\DemoApi;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use CustomerHelpers, DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_register_verify_login_me_and_argon2id_at_rest(): void
    {
        $c = $this->newCustomer('ada@example.test');
        $this->assertSame('Bearer', $c['tokenType']);
        $this->assertTrue($c['customer']['emailVerified']);
        $this->assertStringStartsWith('r7c_', $c['accessToken']);
        $hash = DB::table('customer_account')->where('login_email', 'ada@example.test')->value('password_hash');
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertNull(DB::table('customer_session')->where('access_token_hash', $c['accessToken'])->first(), 'tokens are stored hashed');

        $this->getJson('/api/v1/customer/me', $this->bearer($c['accessToken'], null))->assertOk()->assertJsonPath('email', 'ada@example.test');
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'ADA@example.test', 'password' => self::PW])->assertOk()->assertJsonStructure(['accessToken', 'refreshToken', 'customer' => ['id']]);
        $this->assertGreaterThan(0, DB::table('outbox_event')->where('event_type', 'CustomerRegistered')->count());
        $this->assertGreaterThan(0, DB::table('audit_log')->where('action', 'customer.register')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'CustomerEmailVerified')->count());
    }

    public function test_unverified_login_is_403_and_register_never_reveals_existing_accounts(): void
    {
        $r1 = $this->postJson('/api/v1/customer/auth/register', ['name' => 'Bo', 'email' => 'bo@example.test', 'password' => self::PW])->assertStatus(201);
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'bo@example.test', 'password' => self::PW])->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'bo@example.test', 'password' => 'wrong-password-1'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/v1/customer/auth/verify', ['email' => 'bo@example.test', 'code' => $this->lastMailCode()])->assertOk();
        $r2 = $this->postJson('/api/v1/customer/auth/register', ['name' => 'Bo', 'email' => 'bo@example.test', 'password' => 'Another-Pass-77!'])->assertStatus(201);
        $this->assertSame($r1->json(), $r2->json());
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'bo@example.test', 'password' => 'Another-Pass-77!'])->assertStatus(401); // the existing password was NOT overwritten
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'bo@example.test', 'password' => self::PW])->assertOk();
    }

    public function test_wrong_verification_codes_are_attempt_limited(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Cy', 'email' => 'cy@example.test', 'password' => self::PW])->assertStatus(201);
        $real = $this->lastMailCode();
        $wrong = $real === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/customer/auth/verify', ['email' => 'cy@example.test', 'code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'invalid_verification');
        }
        $this->postJson('/api/v1/customer/auth/verify', ['email' => 'cy@example.test', 'code' => $real])->assertStatus(422); // burnt after 5 guesses
    }

    public function test_lockout_after_repeated_failures(): void
    {
        $this->newCustomer('dee@example.test');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/customer/auth/login', ['email' => 'dee@example.test', 'password' => 'nope-nope-nope'])->assertStatus(401);
        }
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'dee@example.test', 'password' => self::PW])->assertStatus(403)->assertJsonPath('code', 'account_locked');
    }

    public function test_refresh_rotates_and_a_replayed_token_kills_the_chain(): void
    {
        $c = $this->newCustomer();
        $n = $this->postJson('/api/v1/customer/auth/refresh', ['refreshToken' => $c['refreshToken']])->assertOk()->json();
        $this->assertNotSame($c['accessToken'], $n['accessToken']);
        $this->postJson('/api/v1/customer/auth/refresh', ['refreshToken' => $c['refreshToken']])->assertStatus(401); // replay
        $this->getJson('/api/v1/customer/me', $this->bearer($n['accessToken'], null))->assertStatus(401); // chain revoked
    }

    public function test_logout_revokes_the_token(): void
    {
        $c = $this->newCustomer();
        $this->postJson('/api/v1/customer/auth/logout', [], $this->bearer($c['accessToken'], null))->assertOk();
        $this->getJson('/api/v1/customer/me', $this->bearer($c['accessToken'], null))->assertStatus(401);
    }

    public function test_forgot_and_reset_password_revokes_sessions(): void
    {
        $c = $this->newCustomer('eve@example.test');
        $this->postJson('/api/v1/customer/auth/forgot', ['email' => 'nobody@example.test'])->assertStatus(202);
        $this->postJson('/api/v1/customer/auth/forgot', ['email' => 'eve@example.test'])->assertStatus(202);
        $token = $this->lastMailLinkToken();
        $this->postJson('/api/v1/customer/auth/reset', ['token' => $token, 'password' => 'Brand-New-Pass-42'])->assertOk();
        $this->postJson('/api/v1/customer/auth/reset', ['token' => $token, 'password' => 'Brand-New-Pass-43'])->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
        $this->getJson('/api/v1/customer/me', $this->bearer($c['accessToken'], null))->assertStatus(401);
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'eve@example.test', 'password' => 'Brand-New-Pass-42'])->assertOk();
    }

    public function test_customer_tokens_never_work_on_staff_endpoints_and_staff_tokens_never_on_customer_endpoints(): void
    {
        $c = $this->newCustomer();
        $staff = $this->loginAs('manager1');
        foreach (['/api/v1/auth/me', '/api/v1/orders', '/api/v1/payments', '/api/v1/staff', '/api/v1/bookings', '/api/v1/entitlements', '/api/v1/memberships', '/api/v1/catalog/categories', '/api/v1/service-tokens'] as $path) {
            $this->getJson($path, $this->bearer($c['accessToken'], null))->assertStatus($this->expectedForCustomer($path), $path);
        }
        $this->postJson('/api/v1/auth/staff/logout', [], $this->bearer($c['accessToken'], null))->assertStatus(401);
        foreach (['/api/v1/customer/me', '/api/v1/customer/bookings', '/api/v1/customer/entitlements', '/api/v1/customer/memberships'] as $path) {
            $this->getJson($path, $this->bearer($staff['accessToken'], null))->assertStatus(401, $path);
        }
        $this->postJson('/api/v1/public/ticket-orders', [], $this->bearer($staff['accessToken']))->assertStatus(401);
        $this->postJson('/api/v1/customer/auth/logout', [], $this->bearer($staff['accessToken'], null))->assertStatus(401);
        // the service token is neither
        $this->getJson('/api/v1/customer/me', $this->bearer($this->serviceToken(), null))->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $this->bearer($this->serviceToken(), null))->assertStatus(401);
    }

    /** Staff-only route with a customer token: `auth:staff` -> 401; routes that admit customers but need a permission -> 403. */
    private function expectedForCustomer(string $path): int
    {
        return $path === '/api/v1/bookings' ? 403 : 401;
    }

    public function test_service_token_is_read_only_and_revocable(): void
    {
        $t = $this->serviceToken();
        $this->getJson('/api/v1/bookings/resources', $this->bearer($t, null))->assertOk();
        $this->getJson('/api/v1/memberships/plans', $this->bearer($t, null))->assertOk();
        $r = $this->resourceByName('Lawn Tennis Court 1');
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => Ids::fromBinary($r->id), 'start' => now()->addDay()->toIso8601String(), 'end' => now()->addDay()->addHour()->toIso8601String()], $this->bearer($t))->assertStatus(403)->assertJsonPath('code', 'scope_denied');
        $this->getJson('/api/v1/bookings', $this->bearer($t, null))->assertStatus(403);
        $this->getJson('/api/v1/customer/me', $this->bearer($t, null))->assertStatus(401);
        $this->getJson('/api/v1/bookings/resources', $this->bearer('r7s_not-a-real-token', null))->assertStatus(401);

        $owner = $this->loginAs('owner1');
        $new = $this->postJson('/api/v1/service-tokens', ['name' => 'rot'], $this->bearer($owner['accessToken']))->assertStatus(201)->json();
        $this->assertNull(DB::table('service_token')->where('token_hash', $new['token'])->first(), 'only the hash is stored');
        $rot = $this->postJson("/api/v1/service-tokens/{$new['id']}/rotate", ['immediate' => true], $this->bearer($owner['accessToken']))->assertStatus(201)->json();
        $this->getJson('/api/v1/bookings/resources', $this->bearer($new['token'], null))->assertStatus(401);
        $this->getJson('/api/v1/bookings/resources', $this->bearer($rot['token'], null))->assertOk();
        $this->postJson("/api/v1/service-tokens/{$rot['id']}/revoke", [], $this->bearer($owner['accessToken']))->assertOk();
        $this->getJson('/api/v1/bookings/resources', $this->bearer($rot['token'], null))->assertStatus(401);
        $this->postJson('/api/v1/service-tokens', ['name' => 'x'], $this->bearer($this->loginAs('cashier1')['accessToken']))->assertStatus(403);
    }

    public function test_public_site(): void
    {
        $r = $this->getJson('/api/v1/public/site')->assertOk();
        $this->assertSame('NGN', $r->json('currency'));
        $codes = collect($r->json('facilities'))->pluck('code')->all();
        $this->assertContains('POOL_AREA', $codes);
        $byCode = collect($r->json('facilities'))->keyBy('code');
        $this->assertTrue($byCode['POOL_AREA']['onlineTickets']);
        $this->assertTrue($byCode->contains(fn ($f) => $f['onlineBooking']), 'some facility is bookable online');
        $this->assertFalse($byCode['RESTAURANT']['onlineBookable']);
    }
}
