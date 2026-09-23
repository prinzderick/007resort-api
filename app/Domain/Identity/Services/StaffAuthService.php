<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\AuthSession;
use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff authentication: PASSWORD / PIN / NFC_CARD(+PIN) credentials (Argon2id for secrets), opaque
 * short-lived access tokens, rotating refresh tokens. Only SHA-256 hashes of tokens are stored
 * (session table); a DB read never yields a usable token, and revoking a session row is immediate.
 */
class StaffAuthService
{
    public const ACCESS_PREFIX = 'r7a_';

    public const REFRESH_PREFIX = 'r7r_';

    /** Lazily-built hash so unknown users cost the same as wrong secrets (no user enumeration by timing). */
    private static ?string $dummyHash = null;

    public function __construct(private readonly PermissionChecker $permissions) {}

    /**
     * @param  string  $type  PASSWORD | PIN | NFC_CARD (NFC_CARD: $identifier = card uid, $secret = the staff member's PIN — never NFC alone)
     * @return array<string, mixed> AuthResult
     */
    public function login(string $type, string $identifier, string $secret, ?string $ip, ?string $userAgent, ?string $deviceId = null): array
    {
        $now = CarbonImmutable::now('UTC');
        [$account, $credential] = $this->authenticate($type, $identifier, $secret, $ip, 'LOGIN_FAILURE');
        $staff = $account->staff;

        return DB::transaction(function () use ($account, $credential, $staff, $secret, $deviceId, $ip, $userAgent, $now, $type): array {
            $account->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
            $credential->forceFill(['last_used_at' => $now])->save();
            if (in_array($credential->credential_type, [Credential::PASSWORD, Credential::PIN], true) && Hash::needsRehash($credential->credential_hash)) {
                $credential->forceFill(['credential_hash' => Hash::make($secret)])->save();
            }

            [$session, $tokens] = $this->issueSession($account, $deviceId, $ip, $userAgent, $now);

            Audit::record(
                'staff.login', 'Session', $session->id,
                new: ['sessionId' => $session->id, 'username' => $account->username, 'credentialType' => $type, 'issuedAt' => $now->format('Y-m-d\TH:i:s.u\Z')],
                organizationId: $staff->organization_id, siteId: $staff->site_id, actorStaffId: $staff->id, deviceId: $deviceId,
            );

            return $this->authResult($session, $tokens, $staff);
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
                throw ApiProblem::unauthenticated('unauthenticated', 'The refresh token is invalid.');
            }
            $account = $session->account;
            $staff = $account?->staff;

            if ($session->revoked_at !== null) {
                if ($session->replaced_by_session_id !== null) { // a rotated token replayed => assume theft
                    $this->revokeChain($session->replaced_by_session_id, 'refresh_token_reuse', $now);
                    Audit::securityEvent('REFRESH_TOKEN_REUSE', 'CRITICAL', $staff?->id, $ip, ['sessionId' => $session->id]);
                }

                return ApiProblem::unauthenticated('unauthenticated', 'The refresh token is no longer valid.'); // returned (not thrown) so the chain revocation commits
            }
            if ($session->expires_at->lte($now)) {
                throw ApiProblem::unauthenticated('token_expired', 'The refresh token has expired. Sign in again.');
            }
            if ($account === null || ! $account->is_active || $staff === null || ! $staff->is_active || $staff->deleted_at !== null) {
                throw ApiProblem::unauthenticated('unauthenticated', 'This account is inactive.');
            }

            [$new, $tokens] = $this->issueSession($account, $session->device_id, $ip, $userAgent, $now);
            $session->forceFill(['revoked_at' => $now, 'revoked_reason' => 'rotated', 'replaced_by_session_id' => $new->id])->save();

            return $this->authResult($new, $tokens, $staff);
        });

        if ($result instanceof ApiProblem) {
            throw $result;
        }

        return $result;
    }

    /** Revoke the current session, or every live session of the account. */
    public function logout(AuthSession $session, bool $allSessions = false): void
    {
        DB::transaction(function () use ($session, $allSessions): void {
            $query = AuthSession::query()->whereNull('revoked_at');
            $allSessions ? $query->where('user_account_id', $session->user_account_id) : $query->whereKey($session->id);
            $rows = $query->lockForUpdate()->get();
            foreach ($rows as $row) {
                $row->forceFill(['revoked_at' => now('UTC'), 'revoked_reason' => $allSessions ? 'logout_all' : 'logout'])->save();
            }
            if ($rows->isNotEmpty()) {
                Audit::record('staff.logout', 'Session', $session->id, new: ['sessionId' => $session->id, 'allSessions' => $allSessions, 'revoked' => $rows->count()]);
            }
        });
    }

    public function revokeSession(string $sessionId, ?string $reason = null): void
    {
        DB::transaction(function () use ($sessionId, $reason): void {
            $session = AuthSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw ApiProblem::notFound('not_found', 'Session was not found.');
            }
            if ($session->revoked_at === null) {
                $session->forceFill(['revoked_at' => now('UTC'), 'revoked_reason' => $reason ?: 'revoked'])->save();
            }
            Audit::record('session.revoke', 'Session', $session->id, new: ['sessionId' => $session->id, 'reason' => $reason, 'userAccountId' => $session->user_account_id]);
        });
    }

    /**
     * Supervisor re-authentication (contract POST /auth/staff/step-up): verify the APPROVER's credential and that they
     * hold `$permission`; returns the approver account/staff. Token issuing is StepUpService's job.
     *
     * @return array{0: UserAccount, 1: Staff}
     */
    public function verifyApprover(string $type, string $identifier, string $secret, string $permission, ?string $ip): array
    {
        [$account] = $this->authenticate($type, $identifier, $secret, $ip, 'STEP_UP_FAILURE');
        $staff = $account->staff;
        if (! $this->permissions->can($staff->id, $permission)) {
            Audit::securityEvent('STEP_UP_DENIED', 'WARNING', $staff->id, $ip, ['permission' => $permission]);
            throw ApiProblem::permissionDenied($permission);
        }

        return [$account, $staff];
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

        if ($session === null || $session->revoked_at !== null || $session->expires_at->lte($now)) {
            return null;
        }
        if ($session->access_expires_at === null || $session->access_expires_at->lte($now)) {
            RequestContext::set(RequestContext::TOKEN_EXPIRED, '1');

            return null;
        }
        $account = $session->account;
        $staff = $account?->staff;
        if ($account === null || ! $account->is_active || $staff === null || ! $staff->is_active || $staff->deleted_at !== null) {
            return null;
        }

        return $session;
    }

    /** Contract `Staff` object (id, displayName, staffNumber, roles[], permissions[], facilityIds[]). @return array<string, mixed> */
    public function staffPayload(Staff $staff): array
    {
        $grants = $this->permissions->effective($staff->id);
        $roles = DB::table('role_assignment as ra')->join('role as r', 'r.id', '=', 'ra.role_id')
            ->where('ra.staff_id', Ids::toBinary($staff->id))->where('ra.is_active', 1)->whereNull('ra.deleted_at')
            ->distinct()->orderBy('r.code')->pluck('r.code')->all();

        return [
            'id' => $staff->id,
            'displayName' => $staff->displayName(),
            'staffNumber' => $staff->staff_number,
            'roles' => $roles,
            'permissions' => $grants->pluck('permission')->unique()->sort()->values()->all(),
            'facilityIds' => $this->permissions->facilityIds($staff->id),
        ];
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Resolve + verify credentials with lockout accounting. @return array{0: UserAccount, 1: Credential}
     */
    private function authenticate(string $type, string $identifier, string $secret, ?string $ip, string $failureEvent): array
    {
        $account = $this->resolveAccount($type, $identifier);
        if ($account === null) {
            Hash::check($secret, self::dummyHash());
            Audit::securityEvent($failureEvent, 'INFO', ip: $ip, details: ['identifier' => $type === 'NFC_CARD' ? '(card)' : $identifier, 'credentialType' => $type, 'reason' => 'unknown_identity']);
            throw $this->invalidCredentials();
        }

        if ($account->locked_until !== null && $account->locked_until->isFuture()) {
            Audit::securityEvent('LOGIN_BLOCKED_LOCKED', 'WARNING', $account->staff_id, $ip, ['username' => $account->username]);
            throw ApiProblem::locked('account_locked', 'This account is temporarily locked. Try again later.', [
                'meta' => ['lockedUntil' => $account->locked_until->utc()->format('Y-m-d\TH:i:s.v\Z')],
            ]);
        }
        if ($account->locked_until !== null) { // lock expired: start a fresh window
            $account->forceFill(['locked_until' => null, 'failed_login_count' => 0])->save();
        }

        // NFC_CARD proves possession of the card; the secret is the staff PIN (never NFC alone).
        $secretType = $type === Credential::NFC_CARD ? Credential::PIN : $type;
        $credential = $this->activeCredential($account, $secretType);
        if ($credential === null || $secret === '' || ! Hash::check($secret, $credential->credential_hash)) {
            if ($credential === null) {
                Hash::check($secret, self::dummyHash());
            }
            $this->registerFailure($account, $type, $ip, $failureEvent);
            throw $this->invalidCredentials();
        }

        $staff = $account->staff;
        if (! $account->is_active || $staff === null || ! $staff->is_active || $staff->deleted_at !== null) {
            Audit::securityEvent('LOGIN_BLOCKED_INACTIVE', 'INFO', $account->staff_id, $ip, ['username' => $account->username]);
            throw ApiProblem::locked('account_locked', 'This account is inactive.');
        }

        return [$account, $credential];
    }

    private function resolveAccount(string $type, string $identifier): ?UserAccount
    {
        if ($type === Credential::NFC_CARD) {
            $cred = Credential::query()->where('credential_type', Credential::NFC_CARD)->where('is_active', 1)
                ->where('credential_hash', self::cardHash($identifier))->first();

            return $cred ? UserAccount::query()->find($cred->user_account_id) : null;
        }

        return UserAccount::query()->where('username', $identifier)->first()
            ?? UserAccount::query()->whereIn('staff_id', function ($q) use ($identifier) {
                $q->select('id')->from('staff')->where('staff_number', $identifier);
            })->first();
    }

    /** NFC card uids are identifiers, not secrets; stored as a normalised SHA-256 for lookup only. */
    public static function cardHash(string $uid): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $uid)));
    }

    private function activeCredential(UserAccount $account, string $type): ?Credential
    {
        return Credential::query()->where('user_account_id', $account->id)
            ->where('credential_type', $type)->where('is_active', 1)
            ->orderByDesc('created_at')->first();
    }

    private function registerFailure(UserAccount $account, string $type, ?string $ip, string $event): void
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
            'username' => $account->username, 'credentialType' => $type, 'failedCount' => $count, 'locked' => $locked,
        ]);
    }

    private function invalidCredentials(): ApiProblem
    {
        return ApiProblem::unauthenticated('invalid_credentials', 'Invalid credentials.');
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

    /**
     * Contract AuthResult, plus a few flat legacy fields (sessionId, staffId, displayName, *ExpiresAt) that clients may ignore.
     *
     * @param  array{access: string, refresh: string}  $tokens
     * @return array<string, mixed>
     */
    private function authResult(AuthSession $session, array $tokens, Staff $staff): array
    {
        $fmt = fn ($d) => $d->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'accessToken' => $tokens['access'],
            'refreshToken' => $tokens['refresh'],
            'expiresInSeconds' => max(0, (int) now('UTC')->diffInSeconds($session->access_expires_at, false)),
            'staff' => $this->staffPayload($staff),
            'session' => ['id' => $session->id, 'expiresAt' => $fmt($session->expires_at), 'deviceId' => $session->device_id],
            'tokenType' => 'Bearer',
            'accessTokenExpiresAt' => $fmt($session->access_expires_at),
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
