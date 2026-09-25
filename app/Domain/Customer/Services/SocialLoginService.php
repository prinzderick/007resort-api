<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\CustomerAccount;
use App\Domain\Customer\Models\CustomerIdentity;
use App\Domain\Customer\Social\GoogleIdTokenVerifier;
use App\Domain\Customer\Social\IdTokenException;
use App\Domain\Identity\Models\Customer;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Social sign-up / sign-in (docs/CUSTOMER_SOCIAL_LOGIN.md). The OAuth code flow is the WEBSITE's; this service only ever sees
 * claims from the website's service token (scope customer.social) or a server-verified Google id token, and never stores a
 * provider access token.
 *
 * Matching (one transaction, idempotent, safe under concurrent first logins via unique constraints + retry):
 *   1. known identity (provider + provider_user_id) -> sign in;
 *   2. VERIFIED email + existing account -> link if the account's email is verified or it has no password; otherwise 409 and an
 *      emailed confirmation (pre-registration hijack guard);
 *   3. else create. An unverified/absent email is never stored as a login email and never used to match.
 */
class SocialLoginService
{
    private const FMT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly CustomerAuthService $auth, private readonly GoogleIdTokenVerifier $google) {}

    /** @return list<array{id: string, enabled: bool, idTokenVerification: bool, emailTrusted: bool}> */
    public function providers(): array
    {
        $enabled = (array) config('customer.social.enabled');
        $trusted = (array) config('customer.social.trusted_email_providers');

        return array_map(fn (string $p) => [
            'id' => $p, 'enabled' => in_array($p, $enabled, true),
            'idTokenVerification' => $p === 'google' && $this->google->configured(), 'emailTrusted' => in_array($p, $trusted, true),
        ], (array) config('customer.social.known_providers'));
    }

    // ------------------------------------------------------------------------------------------------ claims

    /**
     * Turn the request into trusted, normalised claims.
     *
     * @param  array<string, mixed>  $in  validated request
     * @return array<string, mixed>
     */
    public function claims(array $in, bool $publicCaller): array
    {
        $provider = strtolower((string) $in['provider']);
        if (! in_array($provider, (array) config('customer.social.known_providers'), true) || ! in_array($provider, (array) config('customer.social.enabled'), true)) {
            throw ApiProblem::unprocessable('provider_disabled', 'That sign-in provider is not enabled.');
        }
        $trusted = in_array($provider, (array) config('customer.social.trusted_email_providers'), true);
        $idToken = isset($in['idToken']) && $in['idToken'] !== '' ? (string) $in['idToken'] : null;

        if ($idToken !== null) {
            if ($provider !== 'google') {
                throw ApiProblem::unprocessable('id_token_unsupported', 'Server-side id token verification is only available for Google.');
            }
            if (! $this->google->configured()) {
                throw ApiProblem::unprocessable('provider_disabled', 'Google id token verification is not configured.');
            }
            try {
                $t = $this->google->verify($idToken, $in['nonce'] ?? null);
            } catch (IdTokenException $e) {
                Audit::securityEvent('CUSTOMER_SOCIAL_REJECTED', 'WARNING', null, $in['clientIp'] ?? request()->ip(), ['provider' => $provider, 'reason' => $e->reason]);
                throw new ApiProblem(422, 'id_token_invalid', 'The id token was rejected.', 'Invalid id token', ['meta' => ['reason' => $e->reason]]);
            }
            $sub = $t['sub'];
            $email = $t['email'];
            $requestedVerified = $t['emailVerified'];
            $name = $t['name'];
            $given = $t['givenName'];
            $family = $t['familyName'];
            $avatar = $t['avatarUrl'];
        } else {
            if ($publicCaller || in_array($provider, (array) config('customer.social.require_id_token'), true)) {
                throw ApiProblem::unprocessable('id_token_required', 'A server-verifiable idToken is required.');
            }
            $sub = (string) ($in['providerUserId'] ?? '');
            $email = isset($in['email']) && trim((string) $in['email']) !== '' ? CustomerAuthService::email((string) $in['email']) : null;
            $requestedVerified = (bool) ($in['emailVerified'] ?? false);
            if ($requestedVerified && $email === null) {
                throw ApiProblem::unprocessable('email_verified_without_email', 'emailVerified=true needs an email.');
            }
            $name = $in['name'] ?? null;
            $given = $in['givenName'] ?? null;
            $family = $in['familyName'] ?? null;
            $avatar = $in['avatarUrl'] ?? null;
        }

        return [
            'provider' => $provider, 'sub' => $sub, 'email' => $email,
            // Effective trust: the claim AND a provider whose email claim we honour. Anything else is "unverified".
            'verified' => $email !== null && $requestedVerified && $trusted,
            'name' => self::clean($name, 200), 'given' => self::clean($given, 100), 'family' => self::clean($family, 100),
            'avatar' => $avatar !== null && str_starts_with((string) $avatar, 'https://') ? mb_substr((string) $avatar, 0, 1024) : null,
            'phone' => $in['phone'] ?? null, 'marketing' => (bool) ($in['marketingConsent'] ?? false), 'terms' => (bool) ($in['termsAccepted'] ?? false),
        ];
    }

    // ------------------------------------------------------------------------------------------------ login

    /**
     * @param  array<string, mixed>  $c  claims()
     * @return array<string, mixed> AuthResult + social flags (`isNewCustomer` tells the controller 200 vs 201)
     */
    public function login(array $c, ?string $ip, ?string $ua): array
    {
        $keys = ['id|'.$c['provider'].'|'.$c['sub']];
        if ($c['verified']) {
            $keys[] = 'em|'.$c['email'];
        }
        $out = $this->serialised($keys, fn () => $this->retrying(fn () => DB::transaction(fn () => $this->loginTx($c, $ip, $ua))));
        if (isset($out['pending'])) { // 409: mail goes out only after the (no-op) transaction committed
            $this->mailLinkCode($out['pending']);
            throw new ApiProblem(409, 'account_link_requires_confirmation', 'This email already has an unverified password account. Confirm with the code we emailed.', 'Link requires confirmation', [
                'meta' => ['confirmation' => ['method' => 'email_code', 'maskedEmail' => self::mask($c['email']), 'expiresInSeconds' => (int) config('customer.social.link_confirm_ttl_minutes') * 60]],
            ]);
        }

        return $out;
    }

    private function loginTx(array $c, ?string $ip, ?string $ua): array
    {
        $identity = CustomerIdentity::query()->where('provider', $c['provider'])->where('provider_user_id', $c['sub'])->lockForUpdate()->first();
        if ($identity !== null) {
            return $this->signInKnown($identity, $c, $ip, $ua);
        }
        if ($c['verified']) {
            $r = $this->matchByEmail($c, $ip, $ua);
            if ($r !== null) {
                return $r;
            }
        }

        return $this->createCustomer($c, $ip, $ua);
    }

    private function signInKnown(CustomerIdentity $identity, array $c, ?string $ip, ?string $ua): array
    {
        $account = CustomerAccount::query()->with('customer')->where('customer_id', $identity->customer_id)->lockForUpdate()->first();
        $this->assertUsable($account);
        $now = now('UTC')->format(self::FMT);
        $upd = ['last_login_at' => $now];
        if ($c['avatar'] !== null) {
            $upd['avatar_url'] = $c['avatar'];
        }
        DB::table('customer_identity')->where('id', Ids::toBinary($identity->id))->update($upd);
        DB::table('customer_account')->where('id', Ids::toBinary($account->id))->update(['last_login_at' => $now, 'failed_login_count' => 0, 'locked_until' => null]);
        Audit::record('customer.social.login', 'Customer', $account->customer_id, null, ['provider' => $c['provider'], 'identityId' => $identity->id, 'ip' => $ip]);

        return $this->respond($account->refresh()->load('customer'), $identity->refresh(), false, false, $c, $ip, $ua);
    }

    /** @return ?array<string, mixed> null = no existing customer for this email (=> create) */
    private function matchByEmail(array $c, ?string $ip, ?string $ua): ?array
    {
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        $email = $c['email'];
        $account = CustomerAccount::query()->with('customer')->where('login_email', $email)->lockForUpdate()->first();
        if ($account === null) {
            $customer = Customer::query()->where('organization_id', $org)->where('email', $email)->lockForUpdate()->first();
            if ($customer === null) {
                return null;
            }
            if (CustomerAccount::query()->where('customer_id', $customer->id)->exists()) {
                // The customer's email matches but their login is under another address: ambiguous, refuse to guess.
                throw ApiProblem::conflict('identity_conflict', 'This email belongs to a customer with a different login. Sign in with your existing account and link the provider there.');
            }
            // Walk-in customer without an online login: the provider proved the mailbox, adopt the record.
            $account = CustomerAccount::create(['customer_id' => $customer->id, 'login_email' => $email, 'password_hash' => null, 'email_verified_at' => now('UTC'), 'terms_accepted_at' => $c['terms'] ? now('UTC') : null]);
            $account->setRelation('customer', $customer);
            $identity = $this->insertIdentity($customer->id, $c);
            Audit::record('customer.social.link', 'Customer', $customer->id, null, ['provider' => $c['provider'], 'identityId' => $identity->id, 'adopted' => true, 'ip' => $ip]);
            CustomerAuthService::announceEmailVerified($customer->id, $email, $org, 'social');

            return $this->respond($account, $identity, false, true, $c, $ip, $ua);
        }
        $this->assertUsable($account);
        if (CustomerIdentity::query()->where('customer_id', $account->customer_id)->where('provider', $c['provider'])->exists()) {
            throw ApiProblem::conflict('identity_conflict', 'This customer already has a different '.$c['provider'].' account linked.');
        }
        if ($account->email_verified_at === null && $account->password_hash !== null) {
            return ['pending' => $this->issueLinkConfirmation($account, $c, $ip)];
        }
        if ($account->email_verified_at === null) {
            DB::table('customer_account')->where('id', Ids::toBinary($account->id))->update(['email_verified_at' => now('UTC')->format(self::FMT)]);
            CustomerAuthService::announceEmailVerified($account->customer_id, $email, $account->customer->organization_id, 'social');
        }
        $identity = $this->insertIdentity($account->customer_id, $c);
        Audit::record('customer.social.link', 'Customer', $account->customer_id, null, ['provider' => $c['provider'], 'identityId' => $identity->id, 'ip' => $ip]);

        return $this->respond($account->refresh()->load('customer'), $identity, false, true, $c, $ip, $ua);
    }

    private function createCustomer(array $c, ?string $ip, ?string $ua): array
    {
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        if (! $c['terms']) {
            throw ApiProblem::unprocessable('terms_not_accepted', 'The terms must be accepted to create an account.');
        }
        $name = $c['name'] ?? self::clean(trim(($c['given'] ?? '').' '.($c['family'] ?? '')), 200);
        if ($name === null && $c['email'] !== null) {
            $name = self::localPart($c['email']);
        }
        if ($name === null) {
            throw ApiProblem::unprocessable('profile_insufficient', 'The provider gave neither a name nor an email.');
        }
        $now = now('UTC');
        $customer = Customer::create([
            'organization_id' => $org, 'full_name' => $name, 'phone' => $this->freePhone($org, $c['phone']), 'email' => $c['verified'] ? $c['email'] : null,
        ]);
        $account = CustomerAccount::create([
            'customer_id' => $customer->id, 'login_email' => $c['verified'] ? $c['email'] : null, 'password_hash' => null,
            'email_verified_at' => $c['verified'] ? $now : null, 'terms_accepted_at' => $now, 'marketing_consent_at' => $c['marketing'] ? $now : null,
        ]);
        $account->setRelation('customer', $customer);
        $identity = $this->insertIdentity($customer->id, $c);
        Audit::record('customer.social.register', 'Customer', $customer->id, null, ['provider' => $c['provider'], 'identityId' => $identity->id, 'accountId' => $account->id, 'emailVerified' => $c['verified'], 'marketingConsent' => $c['marketing'], 'ip' => $ip]);
        Outbox::record('CustomerRegistered', 'Customer', $customer->id, [
            'customerId' => $customer->id, 'name' => $customer->full_name, 'email' => $customer->email, 'phone' => $customer->phone, 'channel' => 'ONLINE',
        ], organizationId: $org);
        if ($c['verified']) {
            CustomerAuthService::announceEmailVerified($customer->id, $c['email'], $org, 'social');
        }

        return $this->respond($account, $identity, true, false, $c, $ip, $ua);
    }

    // ------------------------------------------------------------------------------------------------ link confirmation (409 path)

    /** @return array{email: string, name: string, code: string, token: string}|array{} */
    private function issueLinkConfirmation(CustomerAccount $account, array $c, ?string $ip): array
    {
        $id = Ids::toBinary($account->id);
        $recent = DB::table('customer_email_token')->where('customer_account_id', $id)->where('purpose', 'LINK')->where('created_at', '>', now('UTC')->subMinute()->format(self::FMT))->exists();
        Audit::record('customer.social.link.pending', 'Customer', $account->customer_id, null, ['provider' => $c['provider'], 'ip' => $ip]);
        if ($recent) {
            return ['email' => $account->login_email, 'name' => '', 'code' => '', 'token' => '']; // already mailed within the minute: same 409, no second mail
        }
        DB::table('customer_email_token')->where('customer_account_id', $id)->where('purpose', 'LINK')->whereNull('consumed_at')->update(['consumed_at' => now('UTC')->format(self::FMT)]);
        $tokenId = Ids::uuid7();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('customer_email_token')->insert([
            'id' => Ids::toBinary($tokenId), 'customer_account_id' => $id, 'purpose' => 'LINK', 'code_hash' => self::codeHash($code, Ids::toBinary($tokenId)),
            'token_hash' => hash('sha256', $token), 'expires_at' => now('UTC')->addMinutes((int) config('customer.social.link_confirm_ttl_minutes'))->format(self::FMT),
            'email' => $c['email'], // non-secret provider hints only; never tokens
            'payload' => json_encode(['provider' => $c['provider'], 'sub' => $c['sub'], 'avatar' => $c['avatar'], 'verified' => true]),
        ]);

        return ['email' => $account->login_email, 'name' => $account->customer->full_name, 'code' => $code, 'token' => $token];
    }

    private function mailLinkCode(array $p): void
    {
        if (($p['code'] ?? '') === '') {
            return;
        }
        $link = rtrim((string) config('customer.web_url'), '/').'/social/confirm?token='.$p['token'];
        Mail::raw("Hello {$p['name']},\n\nSomeone signed in to 007 Resort & Spa with a social account that uses this email address. To connect it to your existing account, your confirmation code is: {$p['code']}\n(valid for ".config('customer.social.link_confirm_ttl_minutes')." minutes)\n\nOr open: {$link}\n\nIf this was not you, ignore this email.", fn ($m) => $m->to($p['email'])->subject('Confirm your 007 Resort & Spa sign-in'));
    }

    /** @return array<string, mixed> */
    public function confirmLink(?string $email, ?string $code, ?string $token, ?string $ip, ?string $ua): array
    {
        $now = CarbonImmutable::now('UTC');
        $invalid = fn () => new ApiProblem(422, 'invalid_link_confirmation', 'That confirmation is invalid or has expired.', 'Invalid confirmation');
        $row = null;
        if ($token !== null && $token !== '') {
            $row = DB::table('customer_email_token')->where('token_hash', hash('sha256', $token))->where('purpose', 'LINK')->first();
        } elseif ($email !== null && $email !== '') {
            $acct = CustomerAccount::query()->where('login_email', CustomerAuthService::email($email))->first();
            $row = $acct === null ? null : DB::table('customer_email_token')->where('customer_account_id', Ids::toBinary($acct->id))->where('purpose', 'LINK')->whereNull('consumed_at')->orderByDesc('created_at')->first();
        }
        if ($row === null || $row->consumed_at !== null || CarbonImmutable::parse($row->expires_at, 'UTC')->lte($now)) {
            throw $invalid();
        }
        if ($token === null || $token === '') {
            $n = DB::table('customer_email_token')->where('id', $row->id)->where('attempts', '<', (int) config('customer.verify_max_attempts'))->increment('attempts');
            if ($n === 0) {
                DB::table('customer_email_token')->where('id', $row->id)->update(['consumed_at' => $now->format(self::FMT)]);
                throw $invalid();
            }
            if (! hash_equals((string) $row->code_hash, self::codeHash((string) $code, $row->id))) {
                Audit::securityEvent('CUSTOMER_SOCIAL_LINK_CONFIRM_FAILURE', 'INFO', null, $ip, ['accountId' => Ids::fromBinary($row->customer_account_id)]);
                throw $invalid();
            }
        }
        $out = $this->retrying(fn () => DB::transaction(function () use ($row, $now, $ip, $ua, $invalid) {
            if (DB::table('customer_email_token')->where('id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => $now->format(self::FMT)]) === 0) {
                return $invalid();
            }
            $p = json_decode((string) $row->payload, true) ?: [];
            $account = CustomerAccount::query()->with('customer')->lockForUpdate()->findOrFail(Ids::fromBinary($row->customer_account_id));
            $this->assertUsable($account);
            $exists = CustomerIdentity::query()->where('provider', $p['provider'])->where('provider_user_id', $p['sub'])->first();
            if ($exists !== null && $exists->customer_id !== $account->customer_id) {
                return ApiProblem::conflict('identity_already_linked', 'That sign-in belongs to another customer.');
            }
            $c = ['provider' => $p['provider'], 'sub' => $p['sub'], 'email' => $row->email, 'verified' => true, 'avatar' => $p['avatar'] ?? null, 'terms' => false, 'marketing' => false, 'phone' => null, 'name' => null, 'given' => null, 'family' => null];
            if ($exists === null && CustomerIdentity::query()->where('customer_id', $account->customer_id)->where('provider', $p['provider'])->exists()) {
                return ApiProblem::conflict('identity_conflict', 'This customer already has a different '.$p['provider'].' account linked.');
            }
            $nowS = $now->format(self::FMT);
            // Mailbox owner is now proven twice (provider + code): kill whatever password was pre-registered by a stranger, and its sessions.
            DB::table('customer_account')->where('id', Ids::toBinary($account->id))->update([
                'password_hash' => null, 'password_changed_at' => $nowS, 'email_verified_at' => $account->email_verified_at?->format(self::FMT) ?? $nowS,
                'failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => $nowS,
            ]);
            DB::table('customer_session')->where('customer_account_id', Ids::toBinary($account->id))->whereNull('revoked_at')->update(['revoked_at' => $nowS, 'revoked_reason' => 'social_link_confirmed']);
            $identity = $exists ?? $this->insertIdentity($account->customer_id, $c);
            Audit::record('customer.social.link.confirmed', 'Customer', $account->customer_id, null, ['provider' => $p['provider'], 'identityId' => $identity->id, 'passwordCleared' => $account->password_hash !== null, 'ip' => $ip]);
            CustomerAuthService::announceEmailVerified($account->customer_id, (string) $row->email, $account->customer->organization_id, 'social_link_confirm');

            return $this->respond($account->refresh()->load('customer'), $identity, false, true, $c, $ip, $ua);
        }));
        if ($out instanceof ApiProblem) {
            throw $out;
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ authenticated: link / list / unlink

    /**
     * Link another provider to the signed-in customer (claims already vetted by claims()).
     *
     * @return array{identity: array<string, mixed>, created: bool}
     */
    public function linkTo(string $customerId, array $c, ?string $ip): array
    {
        return $this->retrying(fn () => DB::transaction(function () use ($customerId, $c, $ip) {
            $account = CustomerAccount::query()->with('customer')->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            $this->assertUsable($account);
            $existing = CustomerIdentity::query()->where('provider', $c['provider'])->where('provider_user_id', $c['sub'])->first();
            if ($existing !== null) {
                if ($existing->customer_id === $customerId) {
                    return ['identity' => $existing->present(), 'created' => false];
                }
                throw ApiProblem::conflict('identity_already_linked', 'That sign-in is already linked to another customer.');
            }
            if (CustomerIdentity::query()->where('customer_id', $customerId)->where('provider', $c['provider'])->exists()) {
                throw ApiProblem::conflict('identity_conflict', 'You already have a different '.$c['provider'].' account linked. Unlink it first.');
            }
            $identity = $this->insertIdentity($customerId, $c);
            Audit::record('customer.social.link', 'Customer', $customerId, null, ['provider' => $c['provider'], 'identityId' => $identity->id, 'ip' => $ip]);

            return ['identity' => $identity->present(), 'created' => true];
        }));
    }

    /** @return array{items: list<array<string, mixed>>, hasPassword: bool, canUnlink: bool} */
    public function list(string $customerId): array
    {
        $account = CustomerAccount::query()->where('customer_id', $customerId)->firstOrFail();
        $items = CustomerIdentity::query()->where('customer_id', $customerId)->orderBy('linked_at')->get();
        $hasPassword = self::hasUsablePassword($account);

        return ['items' => $items->map->present()->all(), 'hasPassword' => $account->password_hash !== null, 'canUnlink' => $items->count() + ($hasPassword ? 1 : 0) > 1];
    }

    public function unlink(string $customerId, string $identityId, ?string $ip): void
    {
        DB::transaction(function () use ($customerId, $identityId, $ip) {
            $account = CustomerAccount::query()->where('customer_id', $customerId)->lockForUpdate()->firstOrFail(); // serialises concurrent unlinks
            $identity = CustomerIdentity::query()->where('customer_id', $customerId)->where('id', $identityId)->first() ?? throw ApiProblem::notFound('not_found', 'Identity not found.');
            $methods = CustomerIdentity::query()->where('customer_id', $customerId)->count() + (self::hasUsablePassword($account) ? 1 : 0);
            if ($methods <= 1) {
                throw ApiProblem::conflict('last_login_method', 'This is your only way to sign in. Add a password or another provider first.');
            }
            $identity->delete();
            Audit::record('customer.social.unlink', 'Customer', $customerId, null, ['provider' => $identity->provider, 'identityId' => $identityId, 'ip' => $ip]);
        });
    }

    /** GDPR/NDPR: remove every linked identity of a customer (hook for the future erasure flow; FK also cascades). */
    public static function eraseIdentities(string $customerId): int
    {
        return CustomerIdentity::query()->where('customer_id', $customerId)->delete();
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** A password only counts as a login method if the account has a verified login email to sign in with. */
    public static function hasUsablePassword(CustomerAccount $a): bool
    {
        return $a->password_hash !== null && $a->login_email !== null && $a->email_verified_at !== null;
    }

    /** @return array{0: list<string>, 1: list<string>} [missing, recommended] */
    public static function profileGaps(Customer $customer, CustomerAccount $account): array
    {
        $missing = [];
        if ($account->login_email === null || $account->email_verified_at === null) {
            $missing[] = 'email';
        }
        if ($account->login_email !== null && $customer->full_name === self::localPart($account->login_email)) {
            $missing[] = 'name'; // the name was derived from the email because the provider gave none
        }

        return [$missing, $customer->phone === null ? ['phone'] : []];
    }

    private function assertUsable(?CustomerAccount $account): void
    {
        $c = $account?->customer;
        if ($account === null || $c === null || ! $account->is_active || ! $c->is_active || $c->deleted_at !== null) {
            throw ApiProblem::locked('account_locked', 'This account is inactive.');
        }
    }

    private function insertIdentity(string $customerId, array $c): CustomerIdentity
    {
        $now = now('UTC');

        return CustomerIdentity::create([
            'customer_id' => $customerId, 'provider' => $c['provider'], 'provider_user_id' => $c['sub'], 'email_at_link' => $c['email'],
            'email_verified_at_link' => $c['verified'] ? $now : null, 'avatar_url' => $c['avatar'], 'linked_at' => $now, 'last_login_at' => $now,
        ])->refresh();
    }

    /** @return array<string, mixed> */
    private function respond(CustomerAccount $account, CustomerIdentity $identity, bool $isNew, bool $linked, array $c, ?string $ip, ?string $ua): array
    {
        $customer = $account->customer;
        [$missing, $recommended] = self::profileGaps($customer, $account);

        return $this->auth->result($account, $ip, $ua) + [
            'isNewCustomer' => $isNew, 'linkedExisting' => $linked, 'needsProfileCompletion' => $missing !== [], 'missing' => $missing, 'recommended' => $recommended,
            'emailSuggestion' => in_array('email', $missing, true) ? ($c['email'] ?? null) : null,
            'identity' => ['id' => $identity->id, 'provider' => $identity->provider, 'linkedAt' => $identity->linked_at->utc()->format('Y-m-d\TH:i:s.v\Z')],
        ];
    }

    /** Retry on the races this design relies on: a concurrent first login won the unique insert, or InnoDB picked us as a deadlock victim. */
    private function retrying(callable $fn): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $fn();
            } catch (UniqueConstraintViolationException|DeadlockException|QueryException $e) {
                $retryable = $e instanceof UniqueConstraintViolationException || $e instanceof DeadlockException
                    || in_array((string) ($e->errorInfo[0] ?? ''), ['40001'], true) || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
                if (! $retryable || $try >= 5) {
                    throw $e;
                }
                usleep(random_int(5, 40) * 1000);
            }
        }
    }

    /**
     * Serialise concurrent first logins of the same identity / the same email with MySQL named locks (taken in sorted order, so no lock-order
     * deadlocks). Unique constraints + retry remain the correctness backstop; the locks only keep the gap-lock deadlock storm away.
     *
     * @param  list<string>  $keys
     */
    private function serialised(array $keys, callable $fn): mixed
    {
        $names = array_map(fn (string $k) => 'r007soc:'.sha1($k), array_values(array_unique($keys)));
        sort($names);
        $held = [];
        try {
            foreach ($names as $n) {
                if ((int) (DB::selectOne('SELECT GET_LOCK(?, 10) AS l', [$n])->l ?? 0) !== 1) {
                    throw ApiProblem::tooManyRequests('Another sign-in for this account is in progress. Retry in a moment.', 1);
                }
                $held[] = $n;
            }

            return $fn();
        } finally {
            foreach ($held as $n) {
                DB::select('SELECT RELEASE_LOCK(?)', [$n]);
            }
        }
    }

    private function freePhone(string $org, ?string $phone): ?string
    {
        $p = $phone === null ? null : preg_replace('/[^0-9+]/', '', $phone);
        if ($p === null || $p === '' || strlen($p) > 32 || Customer::query()->where('organization_id', $org)->where('phone', $p)->exists()) {
            return null;
        }

        return $p;
    }

    private static function clean(mixed $v, int $max): ?string
    {
        $s = is_string($v) ? trim(preg_replace('/\s+/u', ' ', strip_tags($v)) ?? '') : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    public static function localPart(string $email): string
    {
        return mb_substr(explode('@', $email)[0], 0, 200);
    }

    private static function mask(?string $email): string
    {
        [$l, $d] = array_pad(explode('@', (string) $email, 2), 2, '');

        return mb_substr($l, 0, 1).'***@'.$d;
    }

    private static function codeHash(string $code, string $rowId): string
    {
        return hash_hmac('sha256', $code.'|'.bin2hex($rowId), (string) config('app.key'));
    }
}
