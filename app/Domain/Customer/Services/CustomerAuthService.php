<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\CustomerAccount;
use App\Domain\Customer\Models\CustomerSession;
use App\Domain\Identity\Models\Customer;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Online customer identity (separate from staff): Argon2id passwords, email-verification code/token, opaque token pairs
 * (`r7c_` access / `r7x_` refresh, SHA-256 at rest), rotating refresh with reuse detection, lockout, password reset.
 * No user enumeration: register/forgot/resend answer the same for known and unknown emails; wrong-password and unknown-email
 * cost the same (dummy hash). Secrets never reach logs, audit rows or the outbox.
 */
class CustomerAuthService
{
    public const ACCESS_PREFIX = 'r7c_';

    public const REFRESH_PREFIX = 'r7x_';

    private static ?string $dummy = null;

    private const FMT = 'Y-m-d H:i:s.u';

    /** @param array{name: string, email: string, phone?: ?string, password: string} $in */
    public function register(array $in, ?string $ip): void
    {
        $email = self::email($in['email']);
        $mail = null;
        for ($try = 0; $try < 3 && $mail === null; $try++) {
            try {
                $mail = DB::transaction(fn () => $this->registerTx($in, $email, $ip));
            } catch (UniqueConstraintViolationException) {
                continue; // a concurrent registration of the same email won the insert: re-read
            }
        }
        if (is_array($mail)) {
            $this->sendVerification($email, $mail['name'], $mail['code'], $mail['token']);
        }
    }

    /** @return array{name: string, code: string, token: string}|false */
    private function registerTx(array $in, string $email, ?string $ip): array|false
    {
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        $site = Tenant::siteId();
        $account = CustomerAccount::query()->where('login_email', $email)->lockForUpdate()->first();
        if ($account !== null && $account->email_verified_at !== null) {
            Audit::record('customer.register.duplicate', 'Customer', $account->customer_id, null, ['ip' => $ip], organizationId: $org, siteId: $site);

            return false; // already registered: same response as a fresh registration (no enumeration)
        }
        $hash = Hash::make($in['password']);
        if ($account === null) {
            $customer = Customer::query()->where('organization_id', $org)->where('email', $email)->lockForUpdate()->first();
            if ($customer === null) {
                $phone = $this->freePhone($org, $in['phone'] ?? null);
                $customer = Customer::create(['organization_id' => $org, 'full_name' => trim($in['name']), 'phone' => $phone, 'email' => $email]);
            } elseif (CustomerAccount::query()->where('customer_id', $customer->id)->exists()) {
                return false;
            }
            $account = CustomerAccount::create(['customer_id' => $customer->id, 'login_email' => $email, 'password_hash' => $hash, 'password_changed_at' => now('UTC')]);
            Audit::record('customer.register', 'Customer', $customer->id, null, ['ip' => $ip, 'accountId' => $account->id], organizationId: $org, siteId: $site);
            Outbox::record('CustomerRegistered', 'Customer', $customer->id, [
                'customerId' => $customer->id, 'name' => $customer->full_name, 'email' => $email, 'phone' => $customer->phone, 'channel' => 'ONLINE',
            ], organizationId: $org, siteId: $site);
        } else { // unverified account re-registering: latest password wins, new code
            DB::table('customer_account')->where('id', Ids::toBinary($account->id))->update(['password_hash' => $hash, 'password_changed_at' => now('UTC')->format(self::FMT)]);
            Audit::record('customer.register.retry', 'Customer', $account->customer_id, null, ['ip' => $ip], organizationId: $org, siteId: $site);
        }
        [$code, $token] = $this->issueToken($account->id, 'VERIFY', (int) config('customer.verify_ttl_minutes'), true);

        return ['name' => (string) Customer::query()->find($account->customer_id)?->full_name, 'code' => $code, 'token' => $token];
    }

    /** @return array<string, mixed> AuthResult */
    public function verify(?string $email, ?string $code, ?string $token, ?string $ip, ?string $ua): array
    {
        $now = CarbonImmutable::now('UTC');
        $row = null;
        if ($token !== null && $token !== '') {
            $row = DB::table('customer_email_token')->where('token_hash', hash('sha256', $token))->where('purpose', 'VERIFY')->first();
        } elseif ($email !== null && $email !== '') {
            $acct = CustomerAccount::query()->where('login_email', self::email($email))->first();
            $row = $acct === null ? null : DB::table('customer_email_token')->where('customer_account_id', Ids::toBinary($acct->id))
                ->where('purpose', 'VERIFY')->whereNull('consumed_at')->orderByDesc('created_at')->first();
        }
        $invalid = fn () => new ApiProblem(422, 'invalid_verification', 'That verification code is invalid or has expired.', 'Invalid verification');
        if ($row === null || $row->consumed_at !== null || CarbonImmutable::parse($row->expires_at, 'UTC')->lte($now)) {
            throw $invalid();
        }
        if ($token === null || $token === '') {
            // Attempt counter is committed BEFORE the comparison so guesses are always counted (autocommit, outside any transaction).
            $n = DB::table('customer_email_token')->where('id', $row->id)->where('attempts', '<', (int) config('customer.verify_max_attempts'))->increment('attempts');
            if ($n === 0) {
                DB::table('customer_email_token')->where('id', $row->id)->update(['consumed_at' => $now->format(self::FMT)]);
                Audit::securityEvent('CUSTOMER_VERIFY_LOCKED', 'WARNING', null, $ip, ['accountId' => Ids::fromBinary($row->customer_account_id)]);
                throw $invalid();
            }
            if (! hash_equals((string) $row->code_hash, self::codeHash((string) $code, $row->id))) {
                Audit::securityEvent('CUSTOMER_VERIFY_FAILURE', 'INFO', null, $ip, ['accountId' => Ids::fromBinary($row->customer_account_id)]);
                throw $invalid();
            }
        }

        return DB::transaction(function () use ($row, $now, $ip, $ua) {
            $consumed = DB::table('customer_email_token')->where('id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => $now->format(self::FMT)]);
            if ($consumed === 0) {
                throw new ApiProblem(422, 'invalid_verification', 'That verification code is invalid or has expired.', 'Invalid verification');
            }
            $account = CustomerAccount::query()->with('customer')->lockForUpdate()->findOrFail(Ids::fromBinary($row->customer_account_id));
            $first = $account->email_verified_at === null;
            DB::table('customer_account')->where('id', $row->customer_account_id)->update(['email_verified_at' => $account->email_verified_at?->format(self::FMT) ?? $now->format(self::FMT), 'last_login_at' => $now->format(self::FMT)]);
            if ($first) {
                Audit::record('customer.verify', 'Customer', $account->customer_id, null, ['ip' => $ip], organizationId: $account->customer->organization_id, siteId: Tenant::siteId());
                Outbox::record('CustomerEmailVerified', 'Customer', $account->customer_id, ['customerId' => $account->customer_id], organizationId: $account->customer->organization_id);
            }

            return $this->result($account->refresh()->load('customer'), $ip, $ua);
        });
    }

    public function resend(string $email): void
    {
        $account = CustomerAccount::query()->with('customer')->where('login_email', self::email($email))->first();
        if ($account === null || $account->email_verified_at !== null || ! $account->is_active) {
            return;
        }
        // At most one fresh code per minute per account (the IP/email throttle is on the route as well).
        $recent = DB::table('customer_email_token')->where('customer_account_id', Ids::toBinary($account->id))->where('purpose', 'VERIFY')
            ->where('created_at', '>', now('UTC')->subMinute()->format(self::FMT))->exists();
        if ($recent) {
            return;
        }
        [$code, $token] = DB::transaction(fn () => $this->issueToken($account->id, 'VERIFY', (int) config('customer.verify_ttl_minutes'), true));
        $this->sendVerification($account->login_email, $account->customer->full_name, $code, $token);
    }

    /** @return array<string, mixed> AuthResult */
    public function login(string $email, string $password, ?string $ip, ?string $ua): array
    {
        $account = CustomerAccount::query()->with('customer')->where('login_email', self::email($email))->first();
        if ($account === null) {
            Hash::check($password, self::dummy());
            Audit::securityEvent('CUSTOMER_LOGIN_FAILURE', 'INFO', null, $ip, ['reason' => 'unknown_identity']);
            throw ApiProblem::unauthenticated('invalid_credentials', 'Invalid credentials.');
        }
        if ($account->locked_until !== null && $account->locked_until->isFuture()) {
            Audit::securityEvent('CUSTOMER_LOGIN_BLOCKED_LOCKED', 'WARNING', null, $ip, ['accountId' => $account->id]);
            throw ApiProblem::locked('account_locked', 'This account is temporarily locked. Try again later.', ['meta' => ['lockedUntil' => $account->locked_until->utc()->format('Y-m-d\TH:i:s.v\Z')]]);
        }
        if ($account->password_hash === null || ! Hash::check($password, $account->password_hash)) {
            $this->registerFailure($account, $ip);
            throw ApiProblem::unauthenticated('invalid_credentials', 'Invalid credentials.');
        }
        if (! $account->is_active) {
            throw ApiProblem::locked('account_locked', 'This account is inactive.');
        }
        if ($account->email_verified_at === null) {
            throw ApiProblem::forbidden('email_not_verified', 'Please verify your email address before signing in.');
        }

        return DB::transaction(function () use ($account, $password, $ip, $ua) {
            $upd = ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now('UTC')->format(self::FMT)];
            if (Hash::needsRehash($account->password_hash)) {
                $upd['password_hash'] = Hash::make($password);
            }
            DB::table('customer_account')->where('id', Ids::toBinary($account->id))->update($upd);
            Audit::record('customer.login', 'Customer', $account->customer_id, null, ['ip' => $ip], organizationId: $account->customer->organization_id, siteId: Tenant::siteId());

            return $this->result($account, $ip, $ua);
        });
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken, ?string $ip, ?string $ua): array
    {
        $now = CarbonImmutable::now('UTC');
        $out = DB::transaction(function () use ($refreshToken, $ip, $ua, $now) {
            $s = CustomerSession::query()->with('account.customer')->where('refresh_token_hash', hash('sha256', $refreshToken))->lockForUpdate()->first();
            if ($s === null) {
                return ApiProblem::unauthenticated('unauthenticated', 'The refresh token is invalid.');
            }
            if ($s->revoked_at !== null) {
                if ($s->replaced_by_session_id !== null) { // rotated token replayed => assume theft, kill the chain
                    $this->revokeChain($s->replaced_by_session_id, 'refresh_token_reuse', $now);
                    Audit::securityEvent('CUSTOMER_REFRESH_TOKEN_REUSE', 'CRITICAL', null, $ip, ['sessionId' => $s->id]);
                }

                return ApiProblem::unauthenticated('unauthenticated', 'The refresh token is no longer valid.');
            }
            $a = $s->account;
            if ($s->expires_at->lte($now)) {
                return ApiProblem::unauthenticated('token_expired', 'The refresh token has expired. Sign in again.');
            }
            if ($a === null || ! $a->is_active || $a->email_verified_at === null) {
                return ApiProblem::unauthenticated('unauthenticated', 'This account is inactive.');
            }
            $result = $this->result($a, $ip, $ua, $newSession);
            DB::table('customer_session')->where('id', Ids::toBinary($s->id))->update(['revoked_at' => $now->format(self::FMT), 'revoked_reason' => 'rotated', 'replaced_by_session_id' => Ids::toBinary($newSession)]);

            return $result;
        });
        if ($out instanceof ApiProblem) {
            throw $out;
        }

        return $out;
    }

    public function logout(string $accountId, ?string $accessToken, bool $all): void
    {
        $q = DB::table('customer_session')->whereNull('revoked_at')->where('customer_account_id', Ids::toBinary($accountId));
        if (! $all) {
            $q->where('access_token_hash', hash('sha256', (string) $accessToken));
        }
        $q->update(['revoked_at' => now('UTC')->format(self::FMT), 'revoked_reason' => $all ? 'logout_all' : 'logout']);
    }

    public function forgot(string $email): void
    {
        $account = CustomerAccount::query()->with('customer')->where('login_email', self::email($email))->first();
        if ($account === null || ! $account->is_active) {
            Hash::check('x', self::dummy());

            return;
        }
        if ($account->email_verified_at === null) {
            $this->resend($email);

            return;
        }
        [, $token] = DB::transaction(function () use ($account) {
            Audit::record('customer.password.forgot', 'Customer', $account->customer_id, null, null, organizationId: $account->customer->organization_id, siteId: Tenant::siteId());

            return $this->issueToken($account->id, 'RESET', (int) config('customer.reset_ttl_minutes'), false);
        });
        $link = rtrim((string) config('customer.web_url'), '/').'/reset-password?token='.$token;
        Mail::raw("Hello {$account->customer->full_name},\n\nUse this link to choose a new password (valid for ".config('customer.reset_ttl_minutes')." minutes):\n{$link}\n\nIf you did not ask for this, ignore this email.", fn ($m) => $m->to($account->login_email)->subject('Reset your 007 Resort & Spa password'));
    }

    public function reset(string $token, string $password, ?string $ip): void
    {
        $out = DB::transaction(function () use ($token, $password, $ip) {
            $row = DB::table('customer_email_token')->where('token_hash', hash('sha256', $token))->where('purpose', 'RESET')->lockForUpdate()->first();
            if ($row === null || $row->consumed_at !== null || CarbonImmutable::parse($row->expires_at, 'UTC')->lte(now('UTC'))) {
                return new ApiProblem(422, 'invalid_reset_token', 'That reset link is invalid or has expired.', 'Invalid reset token');
            }
            $now = now('UTC')->format(self::FMT);
            DB::table('customer_email_token')->where('id', $row->id)->update(['consumed_at' => $now]);
            DB::table('customer_account')->where('id', $row->customer_account_id)->update([
                'password_hash' => Hash::make($password), 'password_changed_at' => $now, 'failed_login_count' => 0, 'locked_until' => null,
                'email_verified_at' => DB::raw('COALESCE(email_verified_at, \''.$now.'\')'),
            ]);
            DB::table('customer_session')->where('customer_account_id', $row->customer_account_id)->whereNull('revoked_at')->update(['revoked_at' => $now, 'revoked_reason' => 'password_reset']);
            $account = CustomerAccount::query()->with('customer')->findOrFail(Ids::fromBinary($row->customer_account_id));
            Audit::record('customer.password.reset', 'Customer', $account->customer_id, null, ['ip' => $ip], organizationId: $account->customer->organization_id, siteId: Tenant::siteId());

            return null;
        });
        if ($out instanceof ApiProblem) {
            throw $out;
        }
    }

    /** @return array<string, mixed> contract Customer */
    public static function present(Customer $c, CustomerAccount $a): array
    {
        return [
            'id' => $c->id, 'name' => $c->full_name, 'email' => $a->login_email, 'phone' => $c->phone,
            'emailVerified' => $a->email_verified_at !== null, 'createdAt' => $c->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    // ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function result(CustomerAccount $account, ?string $ip, ?string $ua, ?string &$sessionId = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $access = self::ACCESS_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $refresh = self::REFRESH_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $sessionId = Ids::uuid7();
        $accessExp = $now->addMinutes((int) config('customer.access_ttl_minutes'));
        $exp = $now->addDays((int) config('customer.refresh_ttl_days'));
        DB::table('customer_session')->insert([
            'id' => Ids::toBinary($sessionId), 'customer_account_id' => Ids::toBinary($account->id), 'access_token_hash' => hash('sha256', $access),
            'refresh_token_hash' => hash('sha256', $refresh), 'access_expires_at' => $accessExp->format(self::FMT), 'issued_at' => $now->format(self::FMT),
            'expires_at' => $exp->format(self::FMT), 'created_ip' => $ip, 'user_agent' => $ua !== null ? mb_substr($ua, 0, 255) : null,
        ]);
        $customer = $account->relationLoaded('customer') ? $account->customer : $account->customer()->first();
        $fmt = fn ($d) => $d->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'accessToken' => $access, 'refreshToken' => $refresh, 'tokenType' => 'Bearer',
            'expiresInSeconds' => max(0, (int) $now->diffInSeconds($accessExp, false)),
            'accessTokenExpiresAt' => $fmt($accessExp), 'refreshTokenExpiresAt' => $fmt($exp),
            'customer' => self::present($customer, $account),
        ];
    }

    /** @return array{0: ?string, 1: string} [6-digit code (or null), long link token] */
    private function issueToken(string $accountId, string $purpose, int $ttlMinutes, bool $withCode): array
    {
        DB::table('customer_email_token')->where('customer_account_id', Ids::toBinary($accountId))->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now('UTC')->format(self::FMT)]); // one live secret at a time
        $id = Ids::uuid7();
        $code = $withCode ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : null;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('customer_email_token')->insert([
            'id' => Ids::toBinary($id), 'customer_account_id' => Ids::toBinary($accountId), 'purpose' => $purpose,
            'code_hash' => $code === null ? null : self::codeHash($code, Ids::toBinary($id)), 'token_hash' => hash('sha256', $token),
            'expires_at' => now('UTC')->addMinutes($ttlMinutes)->format(self::FMT),
        ]);

        return [$code, $token];
    }

    private function sendVerification(string $email, string $name, ?string $code, string $token): void
    {
        $link = rtrim((string) config('customer.web_url'), '/').'/verify?token='.$token;
        Mail::raw("Hello {$name},\n\nYour 007 Resort & Spa verification code is: {$code}\n(valid for ".config('customer.verify_ttl_minutes')." minutes)\n\nOr open: {$link}\n\nIf you did not create an account, ignore this email.", fn ($m) => $m->to($email)->subject('Verify your 007 Resort & Spa account'));
    }

    private function registerFailure(CustomerAccount $account, ?string $ip): void
    {
        $id = Ids::toBinary($account->id);
        DB::table('customer_account')->where('id', $id)->increment('failed_login_count');
        $count = (int) DB::table('customer_account')->where('id', $id)->value('failed_login_count');
        $locked = $count >= (int) config('customer.max_failed_logins');
        if ($locked) {
            DB::table('customer_account')->where('id', $id)->update(['locked_until' => now('UTC')->addMinutes((int) config('customer.lockout_minutes'))->format(self::FMT), 'failed_login_count' => 0]);
        }
        Audit::securityEvent('CUSTOMER_LOGIN_FAILURE', $locked ? 'WARNING' : 'INFO', null, $ip, ['accountId' => $account->id, 'failedCount' => $count, 'locked' => $locked]);
    }

    private function revokeChain(?string $sessionId, string $reason, CarbonImmutable $now): void
    {
        for ($g = 0; $sessionId !== null && $g < 1000; $g++) {
            $s = CustomerSession::query()->find($sessionId);
            if ($s === null) {
                return;
            }
            if ($s->revoked_at === null) {
                DB::table('customer_session')->where('id', Ids::toBinary($s->id))->update(['revoked_at' => $now->format(self::FMT), 'revoked_reason' => $reason]);
            }
            $sessionId = $s->replaced_by_session_id;
        }
    }

    private function freePhone(string $org, ?string $phone): ?string
    {
        $p = $phone === null ? null : preg_replace('/[^0-9+]/', '', $phone);
        if ($p === null || $p === '' || Customer::query()->where('organization_id', $org)->where('phone', $p)->exists()) {
            return null;
        }

        return $p;
    }

    public static function email(string $e): string
    {
        return mb_strtolower(trim($e));
    }

    /** Low-entropy 6-digit code: keyed HMAC bound to the token row (a DB read alone cannot brute-force it offline without APP_KEY). */
    private static function codeHash(string $code, string $rowId): string
    {
        return hash_hmac('sha256', $code.'|'.bin2hex($rowId), (string) config('app.key'));
    }

    private static function dummy(): string
    {
        return self::$dummy ??= Hash::make(Str::random(16));
    }
}
