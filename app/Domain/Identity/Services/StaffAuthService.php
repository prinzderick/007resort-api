<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\AuthSession;
use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff authentication: PASSWORD / PIN credentials (Argon2id), opaque short-lived access tokens,
 * rotating refresh tokens. Only SHA-256 hashes of tokens are stored (session table); a DB read
 * never yields a usable token, and revoking a session row is immediate.
 */
class StaffAuthService
{
    public const ACCESS_PREFIX = 'r7a_';

    public const REFRESH_PREFIX = 'r7r_';

    /** Lazily-built hash so unknown usernames cost the same as wrong passwords (no user enumeration by timing). */
    private static ?string $dummyHash = null;

    /**
     * @param  array{password?: ?string, pin?: ?string, deviceId?: ?string}  $input
     * @return array<string, mixed>
     */
    public function login(string $username, array $input, ?string $ip, ?string $userAgent): array
    {
        $type = isset($input['pin']) && $input['pin'] !== '' && empty($input['password']) ? Credential::PIN : Credential::PASSWORD;
        $secret = (string) ($type === Credential::PIN ? $input['pin'] : ($input['password'] ?? ''));
        $now = CarbonImmutable::now('UTC');

        $account = UserAccount::query()->where('username', $username)->first();
        if ($account === null) {
            Hash::check($secret, self::dummyHash());
            Audit::securityEvent('LOGIN_FAILURE', 'INFO', ip: $ip, details: ['username' => $username, 'reason' => 'unknown_user']);
            throw $this->invalidCredentials();
        }

        if ($account->locked_until !== null && $account->locked_until->isFuture()) {
            Audit::securityEvent('LOGIN_BLOCKED_LOCKED', 'WARNING', $account->staff_id, $ip, ['username' => $username]);
            throw ApiProblem::locked('account_locked', 'This account is temporarily locked. Try again later.', [
                'lockedUntil' => $account->locked_until->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        }
        if ($account->locked_until !== null) { // lock expired: start a fresh window
            $account->forceFill(['locked_until' => null, 'failed_login_count' => 0])->save();
        }

        $credential = $this->activeCredential($account, $type);
        if ($credential === null || $secret === '' || ! Hash::check($secret, $credential->credential_hash)) {
            if ($credential === null) {
                Hash::check($secret, self::dummyHash());
            }
            $this->registerFailure($account, $username, $ip, $type);
            throw $this->invalidCredentials();
        }

        $staff = $account->staff;
        if (! $account->is_active || $staff === null || ! $staff->is_active || $staff->deleted_at !== null) {
            Audit::securityEvent('LOGIN_BLOCKED_INACTIVE', 'INFO', $account->staff_id, $ip, ['username' => $username]);
            throw ApiProblem::forbidden('account_inactive', 'This account is inactive.');
        }

        return DB::transaction(function () use ($account, $credential, $staff, $input, $ip, $userAgent, $now, $type): array {
            $account->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
            $credential->forceFill(['last_used_at' => $now])->save();
            if (Hash::needsRehash($credential->credential_hash)) {
                $credential->forceFill(['credential_hash' => Hash::make($type === Credential::PIN ? $input['pin'] : $input['password'])])->save();
            }

            [$session, $tokens] = $this->issueSession($account, $input['deviceId'] ?? null, $ip, $userAgent, $now);

            Audit::record(
                'staff.login', 'Session', $session->id,
                new: ['sessionId' => $session->id, 'username' => $account->username, 'credentialType' => $type, 'issuedAt' => $now->format('Y-m-d\TH:i:s.u\Z')],
                organizationId: $staff->organization_id, siteId: $staff->site_id, actorStaffId: $staff->id, deviceId: $input['deviceId'] ?? null,
            );

            return $this->tokenResponse($session, $tokens, $staff);
        });
    }

    /** Rotate: old row revoked (replaced_by), new row issued. Reuse of a rotated token revokes the chain. @return array<string, mixed> */
    public function refresh(string $refreshToken, ?string $ip, ?string $userAgent): array
    {
        $hash = self::sha256($refreshToken);
        $now = CarbonImmutable::now('UTC');

        $result = DB::transaction(function () use ($hash, $ip, $userAgent, $now): array|ApiProblem {
            $session = AuthSession::query()->where('refresh_token_hash', $hash)->lockForUpdate()->first();
            if ($session === null) {
                throw ApiProblem::unauthenticated('invalid_refresh_token', 'The refresh token is invalid.');
            }
            $account = $session->account;
            $staff = $account?->staff;

            if ($session->revoked_at !== null) {
                if ($session->replaced_by_session_id !== null) { // a rotated token replayed => assume theft
                    $this->revokeChain($session->replaced_by_session_id, 'refresh_token_reuse', $now);
                    Audit::securityEvent('REFRESH_TOKEN_REUSE', 'CRITICAL', $staff?->id, $ip, ['sessionId' => $session->id]);
                }

                return ApiProblem::unauthenticated('invalid_refresh_token', 'The refresh token is no longer valid.'); // returned (not thrown) so the chain revocation commits
            }
            if ($session->expires_at->lte($now)) {
                throw ApiProblem::unauthenticated('refresh_token_expired', 'The refresh token has expired. Sign in again.');
            }
            if ($account === null || ! $account->is_active || $staff === null || ! $staff->is_active || $staff->deleted_at !== null) {
                throw ApiProblem::unauthenticated('account_inactive', 'This account is inactive.');
            }

            [$new, $tokens] = $this->issueSession($account, $session->device_id, $ip, $userAgent, $now);
            $session->forceFill(['revoked_at' => $now, 'revoked_reason' => 'rotated', 'replaced_by_session_id' => $new->id])->save();

            return $this->tokenResponse($new, $tokens, $staff);
        });

        if ($result instanceof ApiProblem) {
            throw $result;
        }

        return $result;
    }

    public function logout(AuthSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $fresh = AuthSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if ($fresh !== null && $fresh->revoked_at === null) {
                $fresh->forceFill(['revoked_at' => now('UTC'), 'revoked_reason' => 'logout'])->save();
                Audit::record('staff.logout', 'Session', $fresh->id, new: ['sessionId' => $fresh->id]);
            }
        });
        Cache::forget('stepup:'.$session->id);
    }

    /** @param  ?string  $reason free text stored in revoked_reason */
    public function revokeSession(string $sessionId, ?string $reason = null): void
    {
        DB::transaction(function () use ($sessionId, $reason): void {
            $session = AuthSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw ApiProblem::notFound('session_not_found', 'Session was not found.');
            }
            if ($session->revoked_at === null) {
                $session->forceFill(['revoked_at' => now('UTC'), 'revoked_reason' => $reason ?: 'revoked'])->save();
            }
            Audit::record('session.revoke', 'Session', $session->id, new: ['sessionId' => $session->id, 'reason' => $reason, 'userAccountId' => $session->user_account_id]);
        });
        Cache::forget('stepup:'.$sessionId);
    }

    /** Re-verify the current staff's password/PIN before a sensitive action. @return array<string, mixed> */
    public function stepUp(UserAccount $account, AuthSession $session, array $input, ?string $ip): array
    {
        $type = isset($input['pin']) && $input['pin'] !== '' && empty($input['password']) ? Credential::PIN : Credential::PASSWORD;
        $secret = (string) ($type === Credential::PIN ? $input['pin'] : ($input['password'] ?? ''));
        $credential = $this->activeCredential($account, $type);

        if ($credential === null || $secret === '' || ! Hash::check($secret, $credential->credential_hash)) {
            $this->registerFailure($account, $account->username, $ip, $type, 'STEP_UP_FAILURE');
            throw $this->invalidCredentials();
        }

        $ttl = (int) config('identity.step_up_seconds');
        Cache::put('stepup:'.$session->id, now('UTC')->timestamp, $ttl);

        return ['verified' => true, 'verifiedAt' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'), 'validForSeconds' => $ttl];
    }

    public static function hasRecentStepUp(string $sessionId): bool
    {
        return Cache::has('stepup:'.$sessionId);
    }

    /** Find the session for a presented access token (null unless live & account active). */
    public function sessionForAccessToken(string $token): ?AuthSession
    {
        if (! str_starts_with($token, self::ACCESS_PREFIX)) {
            return null;
        }
        $now = now('UTC');
        $session = AuthSession::query()->with('account.staff')
            ->where('access_token_hash', self::sha256($token))->first();

        if ($session === null || $session->revoked_at !== null
            || $session->access_expires_at === null || $session->access_expires_at->lte($now)
            || $session->expires_at->lte($now)) {
            return null;
        }
        $account = $session->account;
        $staff = $account?->staff;
        if ($account === null || ! $account->is_active || $staff === null || ! $staff->is_active || $staff->deleted_at !== null) {
            return null;
        }

        return $session;
    }

    // ---------------------------------------------------------------------------------------------

    private function activeCredential(UserAccount $account, string $type): ?Credential
    {
        return Credential::query()->where('user_account_id', $account->id)
            ->where('credential_type', $type)->where('is_active', 1)
            ->orderByDesc('created_at')->first();
    }

    private function registerFailure(UserAccount $account, string $username, ?string $ip, string $type, string $event = 'LOGIN_FAILURE'): void
    {
        $max = (int) config('identity.max_failed_logins');
        DB::table('user_account')->where('id', Ids::toBinary($account->id))->increment('failed_login_count');
        $count = (int) DB::table('user_account')->where('id', Ids::toBinary($account->id))->value('failed_login_count');
        $locked = false;
        if ($count >= $max) {
            DB::table('user_account')->where('id', Ids::toBinary($account->id))
                ->update(['locked_until' => now('UTC')->addMinutes((int) config('identity.lockout_minutes'))->format('Y-m-d H:i:s.u')]);
            $locked = true;
        }
        Audit::securityEvent($event, $locked ? 'WARNING' : 'INFO', $account->staff_id, $ip, [
            'username' => $username, 'credentialType' => $type, 'failedCount' => $count, 'locked' => $locked,
        ]);
    }

    private function invalidCredentials(): ApiProblem
    {
        return ApiProblem::unauthenticated('invalid_credentials', 'Invalid username or credentials.');
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(16));
    }

    /** @return array{0: AuthSession, 1: array{access: string, refresh: string}} */
    private function issueSession(UserAccount $account, ?string $deviceId, ?string $ip, ?string $userAgent, CarbonImmutable $now): array
    {
        $access = self::ACCESS_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $refresh = self::REFRESH_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $session = AuthSession::create([
            'user_account_id' => $account->id,
            'device_id' => $deviceId,
            'refresh_token_hash' => self::sha256($refresh),
            'access_token_hash' => self::sha256($access),
            'access_expires_at' => $now->addMinutes((int) config('identity.access_ttl_minutes')),
            'issued_at' => $now,
            'expires_at' => $now->addDays((int) config('identity.refresh_ttl_days')),
            'created_ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);

        return [$session, ['access' => $access, 'refresh' => $refresh]];
    }

    /** @param array{access: string, refresh: string} $tokens @return array<string, mixed> */
    private function tokenResponse(AuthSession $session, array $tokens, Staff $staff): array
    {
        $fmt = fn ($d) => $d->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'tokenType' => 'Bearer',
            'accessToken' => $tokens['access'],
            'accessTokenExpiresAt' => $fmt($session->access_expires_at),
            'refreshToken' => $tokens['refresh'],
            'refreshTokenExpiresAt' => $fmt($session->expires_at),
            'sessionId' => $session->id,
            'staffId' => $staff->id,
            'displayName' => $staff->displayName(),
        ];
    }

    private function revokeChain(?string $sessionId, string $reason, CarbonImmutable $now): void
    {
        $guard = 0;
        while ($sessionId !== null && $guard++ < 1000) {
            $s = AuthSession::query()->whereKey($sessionId)->first();
            if ($s === null) {
                return;
            }
            if ($s->revoked_at === null) {
                $s->forceFill(['revoked_at' => $now, 'revoked_reason' => $reason])->save();
            }
            $sessionId = $s->replaced_by_session_id;
        }
    }

    public static function sha256(string $v): string
    {
        return hash('sha256', $v);
    }
}
