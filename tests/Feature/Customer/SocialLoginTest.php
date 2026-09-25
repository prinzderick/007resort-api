<?php

namespace Tests\Feature\Customer;

use App\Domain\Customer\Events\CustomerEmailVerified;
use App\Domain\Customer\Services\ServiceTokenService;
use App\Domain\Customer\Services\SocialLoginService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\CustomerHelpers;
use Tests\Support\DemoApi;
use Tests\Support\SocialHelpers;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use CustomerHelpers, DemoApi, SocialHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->enableSocial();
    }

    private function me(string $token): array
    {
        return $this->getJson('/api/v1/customer/me', $this->bearer($token, null))->assertOk()->json();
    }

    // ---------------------------------------------------------------- providers + service-token-only

    public function test_providers_lists_enabled_and_requires_a_service_token(): void
    {
        $r = $this->getJson('/api/v1/public/customers/social/providers', $this->svc())->assertOk()->json('providers');
        $by = array_column($r, null, 'id');
        $this->assertTrue($by['google']['enabled']);
        $this->assertTrue($by['google']['idTokenVerification']);
        $this->assertTrue($by['google']['emailTrusted']);
        $this->assertTrue($by['facebook']['enabled']);
        $this->assertFalse($by['facebook']['emailTrusted']);
        $this->assertFalse($by['apple']['enabled']);
        $this->getJson('/api/v1/public/customers/social/providers')->assertStatus(401);
    }

    public function test_only_a_service_token_with_the_social_scope_may_call_login(): void
    {
        $body = $this->claims();
        $this->postJson('/api/v1/public/customers/social/login', $body)->assertStatus(401); // no credential
        $this->postJson('/api/v1/public/customers/social/login', $body, ['Authorization' => 'Bearer r7s_nope'])->assertStatus(401);
        $c = $this->newCustomer();
        $this->postJson('/api/v1/public/customers/social/login', $body, $this->bearer($c['accessToken'], null))->assertStatus(401); // a customer token is not the website
        $staff = $this->loginAs('wait1');
        $this->postJson('/api/v1/public/customers/social/login', $body, ['Authorization' => 'Bearer '.$staff['accessToken']])->assertStatus(401);

        $readOnly = app(ServiceTokenService::class)->create('ro', null, null, null, 'public.read')['token'];
        $this->socialLogin($body, $readOnly)->assertStatus(403)->assertJsonPath('code', 'scope_denied');
        $this->postJson('/api/v1/public/customers/social/link/confirm', ['token' => 'x'], $this->svc($readOnly))->assertStatus(403);
        $socialOnly = app(ServiceTokenService::class)->create('so', null, null, null, 'customer.social')['token'];
        $this->socialLogin($body, $socialOnly)->assertStatus(201);
        $this->getJson('/api/v1/public/site', $this->svc($socialOnly))->assertOk(); // unauthenticated route: untouched
        $this->getJson('/api/v1/memberships/plans', $this->svc($socialOnly))->assertStatus(401); // social-only token has no public.read
        $this->getJson('/api/v1/memberships/plans', $this->svc($readOnly))->assertOk();
    }

    public function test_the_optional_public_id_token_endpoint_is_off_by_default_and_refuses_claims(): void
    {
        $this->postJson('/api/v1/customer/auth/social/token', $this->claims())->assertStatus(404);
        config(['customer.social.id_token_public_enabled' => true]);
        $this->postJson('/api/v1/customer/auth/social/token', $this->claims())->assertStatus(422)->assertJsonPath('code', 'id_token_required');
        $this->fakeGoogle();
        $this->postJson('/api/v1/customer/auth/social/token', ['provider' => 'google', 'idToken' => $this->idToken(), 'termsAccepted' => true])->assertStatus(201)->assertJsonPath('isNewCustomer', true);
    }

    // ---------------------------------------------------------------- rules 1-3

    public function test_new_google_customer_is_created_verified_passwordless_and_audited(): void
    {
        $body = $this->claims(['phone' => '+2348011112222', 'marketingConsent' => true, 'avatarUrl' => 'https://lh3.example.test/a.png']);
        $r = $this->socialLogin($body)->assertStatus(201)->assertJsonPath('isNewCustomer', true)->assertJsonPath('linkedExisting', false)
            ->assertJsonPath('needsProfileCompletion', false)->assertJsonPath('missing', [])->assertJsonPath('recommended', [])
            ->assertJsonPath('customer.email', 'ada.social@example.test')->assertJsonPath('customer.emailVerified', true)->assertJsonPath('identity.provider', 'google');
        $this->assertStringStartsWith('r7c_', $r->json('accessToken'));
        $this->assertSame('ada.social@example.test', $this->me($r->json('accessToken'))['email']);
        $acct = DB::table('customer_account')->where('login_email', 'ada.social@example.test')->first();
        $this->assertNull($acct->password_hash);
        $this->assertNotNull($acct->email_verified_at);
        $this->assertNotNull($acct->terms_accepted_at);
        $this->assertNotNull($acct->marketing_consent_at);
        $ident = DB::table('customer_identity')->first();
        $this->assertSame($body['providerUserId'], $ident->provider_user_id);
        $this->assertNotNull($ident->email_verified_at_link);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.register')->count());
        $audit = DB::table('audit_log')->where('action', 'customer.social.register')->first();
        $this->assertStringNotContainsString('r7c_', (string) $audit->new_value);
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'CustomerRegistered')->count());
        $ev = json_decode((string) DB::table('outbox_event')->where('event_type', 'CustomerEmailVerified')->value('payload'), true);
        $this->assertSame('social', $ev['source']);
    }

    public function test_returning_login_is_idempotent_keyed_by_identity_not_email_and_case_insensitive(): void
    {
        $body = $this->claims(['email' => 'Ada.Social@Example.TEST']);
        $a = $this->socialLogin($body)->assertStatus(201)->json();
        $b = $this->socialLogin($body)->assertOk()->assertJsonPath('isNewCustomer', false)->json();
        $this->assertSame($a['customer']['id'], $b['customer']['id']);
        $this->assertSame('ada.social@example.test', $b['customer']['email']);
        $c = $this->socialLogin($body + ['email' => 'changed@example.test'])->assertOk()->json(); // provider email changed: still the same person
        $this->assertSame($a['customer']['id'], $c['customer']['id']);
        $this->assertSame(1, DB::table('customer')->count());
        $this->assertSame(1, DB::table('customer_identity')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.register')->count());
        $this->assertSame(2, DB::table('audit_log')->where('action', 'customer.social.login')->count());
    }

    public function test_verified_email_links_to_an_existing_verified_password_account_and_password_keeps_working(): void
    {
        $existing = $this->newCustomer('linkme@example.test');
        $r = $this->socialLogin($this->claims(['email' => 'LinkMe@example.test']))->assertOk()->assertJsonPath('linkedExisting', true)->assertJsonPath('isNewCustomer', false);
        $this->assertSame($existing['customer']['id'], $r->json('customer.id'));
        $this->assertSame(1, DB::table('customer')->where('email', 'linkme@example.test')->count());
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'linkme@example.test', 'password' => self::PW])->assertOk();
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.link')->count());
    }

    public function test_walk_in_customer_without_an_account_is_adopted_on_a_verified_email(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Xu Seed', 'email' => 'seed@example.test', 'password' => self::PW])->assertStatus(201);
        $org = DB::table('organization')->value('id');
        $walkIn = Ids::uuid7();
        DB::table('customer')->insert(['id' => Ids::toBinary($walkIn), 'organization_id' => $org, 'full_name' => 'Walk In Guest', 'email' => 'walkin@example.test']);
        $r = $this->socialLogin($this->claims(['email' => 'walkin@example.test']))->assertOk()->assertJsonPath('linkedExisting', true);
        $this->assertSame($walkIn, $r->json('customer.id'));
        $this->assertSame('Walk In Guest', $r->json('customer.name'));
    }

    public function test_an_unverified_password_account_is_never_linked_silently_and_confirmation_wipes_the_planted_password(): void
    {
        // Attacker pre-registers the victim's address with a password and never verifies it.
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Mallory', 'email' => 'victim@example.test', 'password' => 'Attacker-Pass-1!'])->assertStatus(201);
        $body = $this->claims(['email' => 'victim@example.test', 'providerUserId' => 'victim-g']);
        $r = $this->socialLogin($body)->assertStatus(409)->assertJsonPath('code', 'account_link_requires_confirmation');
        $this->assertSame('email_code', $r->json('meta.confirmation.method'));
        $this->assertSame('v***@example.test', $r->json('meta.confirmation.maskedEmail'));
        $this->assertSame(0, DB::table('customer_identity')->count());
        $this->assertNull(DB::table('customer_session')->first());
        $code = $this->lastMailCode();
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.link.pending')->count());
        $this->socialLogin($body)->assertStatus(409); // repeat: same answer, no second mail within the minute
        $this->assertCount(2, app('mail.manager')->mailer()->getSymfonyTransport()->messages()->all()); // verification + one link mail

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/public/customers/social/link/confirm', ['email' => 'victim@example.test', 'code' => $wrong], $this->svc())->assertStatus(422)->assertJsonPath('code', 'invalid_link_confirmation');
        $ok = $this->postJson('/api/v1/public/customers/social/link/confirm', ['email' => 'victim@example.test', 'code' => $code], $this->svc())->assertOk()->assertJsonPath('linkedExisting', true);
        $this->assertTrue($ok->json('customer.emailVerified'));
        $this->assertSame(1, DB::table('customer_identity')->count());
        $this->assertNull(DB::table('customer_account')->where('login_email', 'victim@example.test')->value('password_hash'), 'the planted password is gone');
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'victim@example.test', 'password' => 'Attacker-Pass-1!'])->assertStatus(401);
        $this->postJson('/api/v1/public/customers/social/link/confirm', ['email' => 'victim@example.test', 'code' => $code], $this->svc())->assertStatus(422); // single use
        $this->socialLogin($body)->assertOk()->assertJsonPath('isNewCustomer', false);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.link.confirmed')->count());
    }

    public function test_link_confirmation_by_emailed_link_token_and_attempt_limit(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Mal Lory', 'email' => 'tok@example.test', 'password' => 'Attacker-Pass-1!'])->assertStatus(201);
        $this->socialLogin($this->claims(['email' => 'tok@example.test']))->assertStatus(409);
        $real = $this->lastMailCode();
        $token = $this->lastMailLinkToken();
        $wrong = $real === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/public/customers/social/link/confirm', ['email' => 'tok@example.test', 'code' => $wrong], $this->svc())->assertStatus(422);
        }
        $this->postJson('/api/v1/public/customers/social/link/confirm', ['email' => 'tok@example.test', 'code' => $real], $this->svc())->assertStatus(422); // burnt
        $this->postJson('/api/v1/public/customers/social/link/confirm', ['token' => $token], $this->svc())->assertStatus(422); // the whole secret is burnt, like verify
        // a fresh confirmation resolved by the emailed LINK token instead of the code
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Mal Lory', 'email' => 'tok2@example.test', 'password' => 'Attacker-Pass-1!'])->assertStatus(201);
        $this->socialLogin($this->claims(['email' => 'tok2@example.test', 'providerUserId' => 'tok2-g']))->assertStatus(409);
        $this->postJson('/api/v1/public/customers/social/link/confirm', ['token' => $this->lastMailLinkToken()], $this->svc())->assertOk()->assertJsonPath('linkedExisting', true);
    }

    public function test_an_unverified_account_without_a_password_is_linked_and_verified(): void
    {
        $org = DB::table('organization')->value('id');
        $cid = Ids::uuid7();
        DB::table('customer')->insert(['id' => Ids::toBinary($cid), 'organization_id' => $org, 'full_name' => 'Pending', 'email' => 'nopw@example.test']);
        DB::table('customer_account')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'customer_id' => Ids::toBinary($cid), 'login_email' => 'nopw@example.test']);
        $this->socialLogin($this->claims(['email' => 'nopw@example.test']))->assertOk()->assertJsonPath('customer.emailVerified', true)->assertJsonPath('linkedExisting', true);
    }

    // ---------------------------------------------------------------- untrusted / unverified / no email

    public function test_facebook_email_is_untrusted_by_default_never_links_and_is_not_stored_as_login_email(): void
    {
        $victim = $this->newCustomer('fbvictim@example.test');
        $r = $this->socialLogin($this->claims(['provider' => 'facebook', 'providerUserId' => 'fb-1', 'email' => 'fbvictim@example.test', 'emailVerified' => true]))
            ->assertStatus(201)->assertJsonPath('isNewCustomer', true)->assertJsonPath('needsProfileCompletion', true)->assertJsonPath('missing', ['email'])
            ->assertJsonPath('emailSuggestion', 'fbvictim@example.test')->assertJsonPath('customer.email', null)->assertJsonPath('customer.emailVerified', false);
        $this->assertNotSame($victim['customer']['id'], $r->json('customer.id'));
        $this->assertNull(DB::table('customer_account')->where('customer_id', Ids::toBinary($r->json('customer.id')))->value('login_email'));
        $this->assertNull(DB::table('customer_identity')->where('provider', 'facebook')->value('email_verified_at_link'));
        $this->assertSame(0, DB::table('customer_identity')->where('customer_id', Ids::toBinary($victim['customer']['id']))->count());
        // ...unless the owner explicitly trusts Facebook's email claim
        config(['customer.social.trusted_email_providers' => ['google', 'facebook']]);
        $this->socialLogin($this->claims(['provider' => 'facebook', 'providerUserId' => 'fb-2', 'email' => 'fbvictim@example.test', 'emailVerified' => true]))->assertOk()->assertJsonPath('linkedExisting', true);
    }

    public function test_unverified_claims_from_a_trusted_provider_are_never_used_to_match_or_link(): void
    {
        $victim = $this->newCustomer('unv@example.test');
        $r = $this->socialLogin($this->claims(['email' => 'unv@example.test', 'emailVerified' => false]))->assertStatus(201);
        $this->assertNotSame($victim['customer']['id'], $r->json('customer.id'));
        $r->assertJsonPath('missing', ['email'])->assertJsonPath('emailSuggestion', 'unv@example.test');
    }

    public function test_provider_without_email_creates_a_customer_and_email_is_completed_by_code_later(): void
    {
        $body = ['provider' => 'facebook', 'providerUserId' => 'fb-noemail', 'emailVerified' => false, 'name' => 'Chidi Okafor', 'termsAccepted' => true];
        $r = $this->socialLogin($body)->assertStatus(201)->assertJsonPath('missing', ['email'])->assertJsonPath('emailSuggestion', null)->assertJsonPath('recommended', ['phone']);
        $t = $r->json('accessToken');
        $h = $this->bearer($t, null);
        $this->assertSame(['email'], $this->me($t)['missing']);
        // works as a session, but cannot buy tickets without an email
        $this->postJson('/api/v1/public/ticket-orders', [], $this->bearer($t))->assertStatus(409)->assertJsonPath('code', 'profile_incomplete');
        $this->patchJson('/api/v1/customer/me', ['phone' => '+234 803 000 1111'], $h)->assertOk()->assertJsonPath('phone', '+2348030001111')->assertJsonPath('recommended', []);
        $this->postJson('/api/v1/customer/me/email', ['email' => 'Chidi@Example.test'], $h)->assertStatus(202);
        $code = $this->lastMailCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/customer/me/email/verify', ['code' => $wrong], $h)->assertStatus(422)->assertJsonPath('code', 'invalid_verification');
        $done = $this->postJson('/api/v1/customer/me/email/verify', ['code' => $code], $h)->assertOk()->assertJsonPath('email', 'chidi@example.test')->assertJsonPath('emailVerified', true)->assertJsonPath('needsProfileCompletion', false);
        $this->assertSame('chidi@example.test', DB::table('customer')->where('id', Ids::toBinary($done->json('id')))->value('email'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.email.change')->count());
        // the same person can now sign in again with Facebook and lands in the same account
        $this->socialLogin($body)->assertOk()->assertJsonPath('customer.id', $done->json('id'))->assertJsonPath('needsProfileCompletion', false);
    }

    public function test_adding_an_email_that_belongs_to_another_account_is_refused(): void
    {
        $this->newCustomer('taken@example.test');
        $t = $this->socialLogin(['provider' => 'facebook', 'providerUserId' => 'fb-x', 'emailVerified' => false, 'name' => 'Xi', 'termsAccepted' => true])->json('accessToken');
        $this->postJson('/api/v1/customer/me/email', ['email' => 'taken@example.test'], $this->bearer($t, null))->assertStatus(409)->assertJsonPath('code', 'email_in_use');
    }

    public function test_validation_and_takeover_guards(): void
    {
        $this->socialLogin($this->claims(['email' => null, 'emailVerified' => true]))->assertStatus(422)->assertJsonPath('code', 'email_verified_without_email');
        $this->socialLogin($this->claims(['termsAccepted' => false]))->assertStatus(422)->assertJsonPath('code', 'terms_not_accepted');
        $this->assertSame(0, DB::table('customer')->count());
        $this->socialLogin($this->claims(['provider' => 'apple']))->assertStatus(422)->assertJsonPath('code', 'provider_disabled');
        $this->socialLogin($this->claims(['provider' => 'twitter']))->assertStatus(422);
        $this->socialLogin(['provider' => 'google', 'emailVerified' => true, 'termsAccepted' => true])->assertStatus(422); // no providerUserId
        $this->socialLogin(['provider' => 'facebook', 'providerUserId' => 'fb-n', 'emailVerified' => false, 'termsAccepted' => true])->assertStatus(422)->assertJsonPath('code', 'profile_insufficient');
        config(['customer.social.require_id_token' => ['google']]);
        $this->socialLogin($this->claims())->assertStatus(422)->assertJsonPath('code', 'id_token_required');
        // terms are not needed for an existing identity
        config(['customer.social.require_id_token' => []]);
        $body = $this->claims();
        $this->socialLogin($body)->assertStatus(201);
        $this->socialLogin(['termsAccepted' => false] + $body)->assertOk();
    }

    public function test_second_provider_account_of_the_same_kind_on_a_matching_email_is_a_conflict(): void
    {
        $this->socialLogin($this->claims(['providerUserId' => 'g-one']))->assertStatus(201);
        $this->socialLogin($this->claims(['providerUserId' => 'g-two']))->assertStatus(409)->assertJsonPath('code', 'identity_conflict');
        $this->assertSame(1, DB::table('customer_identity')->count());
    }

    // ---------------------------------------------------------------- social-only accounts and passwords

    public function test_password_login_for_social_only_accounts_fails_generically_and_reset_adds_a_password(): void
    {
        $this->socialLogin($this->claims(['email' => 'soc@example.test']))->assertStatus(201);
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'soc@example.test', 'password' => 'Whatever-Pass-1!'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/v1/customer/auth/forgot', ['email' => 'soc@example.test'])->assertStatus(202);
        $tok = $this->lastMailLinkToken();
        $this->postJson('/api/v1/customer/auth/reset', ['token' => $tok, 'password' => 'Brand-New-Pass-1!'])->assertOk();
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'soc@example.test', 'password' => 'Brand-New-Pass-1!'])->assertOk();
    }

    public function test_set_password_on_a_social_only_account_and_change_requires_the_current_one(): void
    {
        $t = $this->socialLogin($this->claims())->json('accessToken');
        $h = $this->bearer($t, null);
        $this->postJson('/api/v1/customer/me/password', ['password' => 'short'], $h)->assertStatus(422);
        $this->postJson('/api/v1/customer/me/password', ['password' => 'Set-By-Social-1!'], $h)->assertOk();
        $this->assertStringStartsWith('$argon2id$', DB::table('customer_account')->value('password_hash'));
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'ada.social@example.test', 'password' => 'Set-By-Social-1!'])->assertOk();
        $this->getJson('/api/v1/customer/me', $h)->assertOk(); // current session survives
        $this->postJson('/api/v1/customer/me/password', ['password' => 'Another-One-Pass-1!'], $h)->assertStatus(422)->assertJsonPath('code', 'invalid_current_password');
        $this->postJson('/api/v1/customer/me/password', ['password' => 'Another-One-Pass-1!', 'currentPassword' => 'Set-By-Social-1!'], $h)->assertOk();
        $this->assertSame(2, DB::table('audit_log')->where('action', 'customer.password.set')->count());
    }

    public function test_a_no_email_customer_cannot_set_a_password_until_the_email_is_verified(): void
    {
        $t = $this->socialLogin(['provider' => 'facebook', 'providerUserId' => 'fb-pw', 'emailVerified' => false, 'name' => 'No Mail', 'termsAccepted' => true])->json('accessToken');
        $this->postJson('/api/v1/customer/me/password', ['password' => 'Set-By-Social-1!'], $this->bearer($t, null))->assertStatus(409)->assertJsonPath('code', 'email_not_verified');
    }

    // ---------------------------------------------------------------- link / list / unlink

    public function test_link_another_provider_via_website_and_unlink_guard(): void
    {
        $g = $this->socialLogin($this->claims())->assertStatus(201)->json();
        $t = $g['accessToken'];
        $h = $this->bearer($t);
        $fb = ['provider' => 'facebook', 'providerUserId' => 'fb-link', 'emailVerified' => false, 'email' => 'other@example.test'];
        // a bare customer bearer cannot vouch for claims
        $this->postJson('/api/v1/customer/me/social/link', $fb, $h)->assertStatus(422)->assertJsonPath('code', 'id_token_required');
        // website: service token + the customer's own token
        $svcH = $this->svc() + ['X-Customer-Token' => $t, 'Idempotency-Key' => 'link-1'];
        $this->postJson('/api/v1/customer/me/social/link', $fb, $svcH)->assertStatus(201)->assertJsonPath('identity.provider', 'facebook');
        $this->postJson('/api/v1/customer/me/social/link', $fb, ['Idempotency-Key' => 'link-2'] + $svcH)->assertOk(); // already mine: idempotent
        $this->postJson('/api/v1/customer/me/social/link', $fb, ['Authorization' => 'Bearer r7s_dev_booking_web', 'Idempotency-Key' => 'link-3'])->assertStatus(401); // no X-Customer-Token
        $this->assertSame('ada.social@example.test', $this->me($t)['email'], 'linking never changes the login email');
        $list = $this->getJson('/api/v1/customer/me/identities', $h)->assertOk();
        $list->assertJsonPath('hasPassword', false)->assertJsonPath('canUnlink', true);
        $this->assertCount(2, $list->json('items'));
        $ids = array_column($list->json('items'), 'id', 'provider');

        // someone else cannot steal my identity, nor see/remove it
        $other = $this->newCustomer();
        $this->postJson('/api/v1/customer/me/social/link', $fb, $this->svc() + ['X-Customer-Token' => $other['accessToken'], 'Idempotency-Key' => 'link-4'])->assertStatus(409)->assertJsonPath('code', 'identity_already_linked');
        $this->deleteJson('/api/v1/customer/me/identities/'.$ids['facebook'], [], $this->bearer($other['accessToken'], null))->assertStatus(404);
        // another facebook account for the same customer
        $this->postJson('/api/v1/customer/me/social/link', ['providerUserId' => 'fb-other'] + $fb, $this->svc() + ['X-Customer-Token' => $t, 'Idempotency-Key' => 'link-5'])->assertStatus(409)->assertJsonPath('code', 'identity_conflict');

        $this->deleteJson('/api/v1/customer/me/identities/'.$ids['facebook'], [], $h)->assertStatus(204);
        // last login method (no password): refused
        $this->deleteJson('/api/v1/customer/me/identities/'.$ids['google'], [], $h)->assertStatus(409)->assertJsonPath('code', 'last_login_method');
        $this->postJson('/api/v1/customer/me/password', ['password' => 'Now-I-Have-One-1!'], $h)->assertOk();
        $this->deleteJson('/api/v1/customer/me/identities/'.$ids['google'], [], $h)->assertStatus(204);
        $this->assertSame(0, DB::table('customer_identity')->where('customer_id', Ids::toBinary($g['customer']['id']))->count());
        $this->assertSame(2, DB::table('audit_log')->where('action', 'customer.social.unlink')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.link')->count());
    }

    public function test_a_password_account_can_link_with_an_id_token_using_only_its_own_bearer(): void
    {
        $this->fakeGoogle();
        $c = $this->newCustomer('mobile@example.test');
        $r = $this->postJson('/api/v1/customer/me/social/link', ['provider' => 'google', 'idToken' => $this->idToken(['sub' => 'gsub-mobile', 'email' => 'someone.else@example.test'])], $this->bearer($c['accessToken']))->assertStatus(201);
        $this->assertSame('someone.else@example.test', $r->json('identity.email'));
        $this->assertSame('mobile@example.test', $this->me($c['accessToken'])['email']);
        // unlinking is allowed because a password (verified email) remains
        $this->deleteJson('/api/v1/customer/me/identities/'.$r->json('identity.id'), [], $this->bearer($c['accessToken'], null))->assertStatus(204);
    }

    public function test_erase_identities_helper_and_fk_cascade(): void
    {
        $cid = $this->socialLogin($this->claims())->json('customer.id');
        $this->assertSame(1, SocialLoginService::eraseIdentities($cid));
        $this->socialLogin($this->claims(['providerUserId' => 'again']))->assertStatus(200);
        DB::table('customer_session')->delete();
        DB::table('customer_account')->delete();
        DB::table('customer')->where('id', Ids::toBinary($cid))->delete(); // cascades
        $this->assertSame(0, DB::table('customer_identity')->where('customer_id', Ids::toBinary($cid))->count());
    }

    // ---------------------------------------------------------------- Google id token

    public function test_id_token_happy_path_uses_the_verified_claims_and_ignores_body_claims(): void
    {
        $this->fakeGoogle();
        $r = $this->socialLogin(['provider' => 'google', 'idToken' => $this->idToken(['sub' => 'real-sub', 'email' => 'real@example.test']),
            'providerUserId' => 'forged', 'email' => 'forged@example.test', 'emailVerified' => false, 'termsAccepted' => true])->assertStatus(201);
        $this->assertSame('real@example.test', $r->json('customer.email'));
        $this->assertSame('real-sub', DB::table('customer_identity')->value('provider_user_id'));
        $this->assertSame('https://lh3.example.test/a.png', DB::table('customer_identity')->value('avatar_url'));
        $this->assertSame('Ada Social', $r->json('customer.name'));
    }

    public function test_id_token_is_verified_for_signature_audience_issuer_expiry_nonce_and_email_verified(): void
    {
        $this->fakeGoogle();
        $post = fn (string $tok, array $extra = []) => $this->socialLogin(['provider' => 'google', 'idToken' => $tok, 'termsAccepted' => true] + $extra);

        $post($this->idToken(['aud' => 'someone-elses-client']))->assertStatus(422)->assertJsonPath('code', 'id_token_invalid')->assertJsonPath('meta.reason', 'wrong_audience');
        $post($this->idToken(['exp' => time() - 3600, 'iat' => time() - 7200]))->assertStatus(422)->assertJsonPath('meta.reason', 'expired');
        $post($this->idToken(['iss' => 'https://evil.example.test']))->assertStatus(422)->assertJsonPath('meta.reason', 'wrong_issuer');
        $attacker = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($attacker, $apem);
        $post($this->idToken([], $apem))->assertStatus(422)->assertJsonPath('meta.reason', 'bad_signature'); // right kid, wrong key
        $post($this->idToken([], null, 'kid-unknown'))->assertStatus(422)->assertJsonPath('meta.reason', 'unknown_key');
        $post('not.a.jwt')->assertStatus(422)->assertJsonPath('meta.reason', 'malformed');
        $post('abc')->assertStatus(422)->assertJsonPath('meta.reason', 'malformed');
        // alg=none / HS256 confusion is refused before any key is consulted
        $none = rtrim(strtr(base64_encode('{"alg":"none","kid":"kid-1"}'), '+/', '-_'), '=').'.'.rtrim(strtr(base64_encode('{"iss":"https://accounts.google.com","aud":"'.self::CLIENT_ID.'","sub":"x","exp":'.(time() + 999).'}'), '+/', '-_'), '=').'.';
        $post($none)->assertStatus(422)->assertJsonPath('meta.reason', 'malformed');
        $post($this->idToken(['nonce' => 'abc']), ['nonce' => 'other'])->assertStatus(422)->assertJsonPath('meta.reason', 'nonce_mismatch');
        $post($this->idToken(['nonce' => 'abc']), ['nonce' => 'abc'])->assertStatus(201);
        $this->assertSame(1, DB::table('customer')->count(), 'nothing was created by the rejected tokens');
        $this->assertGreaterThanOrEqual(6, DB::table('security_event')->where('event_type', 'CUSTOMER_SOCIAL_REJECTED')->count());
    }

    public function test_id_token_with_email_verified_false_is_treated_as_unverified(): void
    {
        $this->fakeGoogle();
        $victim = $this->newCustomer('idv@example.test');
        $r = $this->socialLogin(['provider' => 'google', 'idToken' => $this->idToken(['email' => 'idv@example.test', 'email_verified' => false, 'sub' => 'gs-unv']), 'termsAccepted' => true])->assertStatus(201);
        $this->assertNotSame($victim['customer']['id'], $r->json('customer.id'));
        $r->assertJsonPath('missing', ['email'])->assertJsonPath('customer.emailVerified', false);
        $this->assertSame(0, DB::table('customer_identity')->where('customer_id', Ids::toBinary($victim['customer']['id']))->count());
    }

    public function test_id_token_requires_configuration_and_only_google(): void
    {
        $this->fakeGoogle();
        config(['customer.social.google.client_ids' => []]);
        $this->socialLogin(['provider' => 'google', 'idToken' => $this->idToken(), 'termsAccepted' => true])->assertStatus(422)->assertJsonPath('code', 'provider_disabled');
        $this->enableSocial();
        $this->socialLogin(['provider' => 'facebook', 'idToken' => $this->idToken(), 'termsAccepted' => true])->assertStatus(422)->assertJsonPath('code', 'id_token_unsupported');
    }

    public function test_jwks_are_cached_and_refetched_only_on_an_unknown_kid_with_a_cooldown(): void
    {
        $this->fakeGoogle();
        $post = fn (string $sub, ?string $tok = null) => $this->socialLogin(['provider' => 'google', 'idToken' => $tok ?? $this->idToken(['sub' => $sub, 'email' => $sub.'@example.test']), 'termsAccepted' => true]);
        $post('s1')->assertStatus(201);
        $post('s2')->assertStatus(201);
        Http::assertSentCount(1); // one fetch for both
        // key rotation: Google now publishes kid-2; the first unknown kid triggers exactly one refetch
        $old = $this->googleKey;
        $this->rotateGoogle('kid-2', [$old['jwk']]);
        $post('s3')->assertStatus(201);
        Http::assertSentCount(2);
        // forged unknown kids cannot make us hammer Google (cooldown)
        $post('x', $this->idToken([], null, 'kid-forged'))->assertStatus(422)->assertJsonPath('meta.reason', 'unknown_key');
        $post('y', $this->idToken([], null, 'kid-forged-2'))->assertStatus(422);
        Http::assertSentCount(2); // the forged kids did not trigger further fetches (cooldown)
    }

    public function test_jwks_unavailable_is_reported_not_trusted(): void
    {
        Http::fake([self::JWKS_URL => Http::response('nope', 500)]);
        $this->fakeGoogleKeyOnly();
        $this->socialLogin(['provider' => 'google', 'idToken' => $this->idToken(), 'termsAccepted' => true])->assertStatus(422)->assertJsonPath('meta.reason', 'jwks_unavailable');
    }

    private function fakeGoogleKeyOnly(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $this->googleKey = ['pem' => $pem, 'kid' => 'kid-1'];
    }

    // ---------------------------------------------------------------- limits, guard, hooks

    public function test_login_is_rate_limited_per_provider_identity(): void
    {
        $body = $this->claims();
        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $this->socialLogin($body);
        }
        $last->assertStatus(429);
        $this->socialLogin($this->claims(['email' => 'someone.new@example.test']))->assertStatus(201); // another identity is unaffected
    }

    public function test_inactive_customers_cannot_sign_in_and_a_no_email_customer_session_is_accepted_by_the_guard(): void
    {
        $body = $this->claims();
        $cid = $this->socialLogin($body)->json('customer.id');
        DB::table('customer_account')->update(['is_active' => 0]);
        $this->socialLogin($body)->assertStatus(403)->assertJsonPath('code', 'account_locked');
        DB::table('customer_account')->update(['is_active' => 1]);
        $this->socialLogin($body)->assertOk();
        $this->assertNotNull($cid);
    }

    public function test_the_email_verified_event_fires_for_social_sign_ups_and_links(): void
    {
        $seen = [];
        Event::listen(CustomerEmailVerified::class, function ($e) use (&$seen) {
            $seen[] = [$e->email, $e->source];
        });
        $this->socialLogin($this->claims(['email' => 'evt@example.test']))->assertStatus(201);
        $this->assertSame([['evt@example.test', 'social']], $seen);
        $this->socialLogin($this->claims(['email' => 'evt@example.test', 'providerUserId' => 'same']))->assertStatus(409); // identity_conflict, no event
        $this->assertCount(1, $seen);
    }

    public function test_refresh_and_logout_work_for_social_sessions(): void
    {
        $r = $this->socialLogin(['provider' => 'facebook', 'providerUserId' => 'fb-r', 'emailVerified' => false, 'name' => 'Ref Resh', 'termsAccepted' => true])->json();
        $n = $this->postJson('/api/v1/customer/auth/refresh', ['refreshToken' => $r['refreshToken']])->assertOk()->json(); // guard accepts identity-verified accounts without a verified email
        $this->postJson('/api/v1/customer/auth/logout', [], $this->bearer($n['accessToken'], null))->assertOk();
    }
}
