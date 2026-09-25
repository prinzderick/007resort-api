<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\CustomerAccount;
use App\Domain\Identity\Models\Customer;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/** Profile completion for (mostly social) customers: name/phone, add-or-change the login email by code, set a password. */
class CustomerProfileService
{
    private const FMT = 'Y-m-d H:i:s.u';

    /** @return array<string, mixed> */
    public function present(string $customerId): array
    {
        $c = Customer::query()->findOrFail($customerId);
        $a = CustomerAccount::query()->where('customer_id', $customerId)->firstOrFail();
        [$missing, $recommended] = SocialLoginService::profileGaps($c, $a);

        return CustomerAuthService::present($c, $a) + ['needsProfileCompletion' => $missing !== [], 'missing' => $missing, 'recommended' => $recommended];
    }

    /** @param array{name?: string, phone?: ?string} $in */
    public function update(string $customerId, array $in): array
    {
        DB::transaction(function () use ($customerId, $in) {
            $c = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $upd = [];
            if (isset($in['name'])) {
                $upd['full_name'] = trim($in['name']);
            }
            if (array_key_exists('phone', $in)) {
                $p = $in['phone'] === null ? null : preg_replace('/[^0-9+]/', '', $in['phone']);
                if ($p !== null && $p !== '' && Customer::query()->where('organization_id', $c->organization_id)->where('phone', $p)->where('id', '!=', $c->id)->exists()) {
                    throw ApiProblem::unprocessable('phone_in_use', 'That phone number is already used by another customer.');
                }
                $upd['phone'] = $p === '' ? null : $p;
            }
            if ($upd !== []) {
                DB::table('customer')->where('id', Ids::toBinary($c->id))->update($upd + ['row_version' => DB::raw('row_version + 1')]);
                Audit::record('customer.profile.update', 'Customer', $c->id, null, ['fields' => array_keys($upd)], organizationId: $c->organization_id);
            }
        });

        return $this->present($customerId);
    }

    public function startEmail(string $customerId, string $email): void
    {
        $email = CustomerAuthService::email($email);
        $account = CustomerAccount::query()->with('customer')->where('customer_id', $customerId)->firstOrFail();
        if ($account->login_email === $email && $account->email_verified_at !== null) {
            return; // already yours and verified: nothing to do (idempotent)
        }
        $mail = DB::transaction(function () use ($account, $email) {
            $taken = CustomerAccount::query()->where('login_email', $email)->where('id', '!=', $account->id)->exists()
                || Customer::query()->where('organization_id', $account->customer->organization_id)->where('email', $email)->where('id', '!=', $account->customer_id)->exists();
            if ($taken) {
                throw ApiProblem::conflict('email_in_use', 'That email address belongs to another account. Sign in with that account and link this provider there.');
            }
            $id = Ids::toBinary($account->id);
            if (DB::table('customer_email_token')->where('customer_account_id', $id)->where('purpose', 'EMAIL')->where('created_at', '>', now('UTC')->subMinute()->format(self::FMT))->exists()) {
                return null; // one code per minute
            }
            DB::table('customer_email_token')->where('customer_account_id', $id)->where('purpose', 'EMAIL')->whereNull('consumed_at')->update(['consumed_at' => now('UTC')->format(self::FMT)]);
            $tokenId = Ids::uuid7();
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            DB::table('customer_email_token')->insert([
                'id' => Ids::toBinary($tokenId), 'customer_account_id' => $id, 'purpose' => 'EMAIL', 'code_hash' => self::codeHash($code, Ids::toBinary($tokenId)),
                'token_hash' => hash('sha256', $token), 'email' => $email, 'expires_at' => now('UTC')->addMinutes((int) config('customer.verify_ttl_minutes'))->format(self::FMT),
            ]);

            return ['name' => $account->customer->full_name, 'code' => $code, 'token' => $token];
        });
        if ($mail !== null) {
            $link = rtrim((string) config('customer.web_url'), '/').'/verify-email?token='.$mail['token'];
            Mail::raw("Hello {$mail['name']},\n\nYour 007 Resort & Spa email verification code is: {$mail['code']}\n(valid for ".config('customer.verify_ttl_minutes')." minutes)\n\nOr open: {$link}\n\nIf you did not ask for this, ignore this email.", fn ($m) => $m->to($email)->subject('Verify your email for 007 Resort & Spa'));
        }
    }

    /** @return array<string, mixed> present() */
    public function verifyEmail(string $customerId, ?string $code, ?string $token, ?string $ip): array
    {
        $now = CarbonImmutable::now('UTC');
        $invalid = fn () => new ApiProblem(422, 'invalid_verification', 'That verification code is invalid or has expired.', 'Invalid verification');
        $account = CustomerAccount::query()->where('customer_id', $customerId)->firstOrFail();
        $q = DB::table('customer_email_token')->where('customer_account_id', Ids::toBinary($account->id))->where('purpose', 'EMAIL')->whereNull('consumed_at');
        $row = ($token !== null && $token !== '') ? $q->where('token_hash', hash('sha256', $token))->first() : $q->orderByDesc('created_at')->first();
        if ($row === null || CarbonImmutable::parse($row->expires_at, 'UTC')->lte($now)) {
            throw $invalid();
        }
        if ($token === null || $token === '') {
            if (DB::table('customer_email_token')->where('id', $row->id)->where('attempts', '<', (int) config('customer.verify_max_attempts'))->increment('attempts') === 0) {
                DB::table('customer_email_token')->where('id', $row->id)->update(['consumed_at' => $now->format(self::FMT)]);
                throw $invalid();
            }
            if (! hash_equals((string) $row->code_hash, self::codeHash((string) $code, $row->id))) {
                Audit::securityEvent('CUSTOMER_EMAIL_VERIFY_FAILURE', 'INFO', null, $ip, ['accountId' => $account->id]);
                throw $invalid();
            }
        }
        try {
            DB::transaction(function () use ($row, $account, $now) {
                if (DB::table('customer_email_token')->where('id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => $now->format(self::FMT)]) === 0) {
                    throw new ApiProblem(422, 'invalid_verification', 'That verification code is invalid or has expired.', 'Invalid verification');
                }
                $a = CustomerAccount::query()->with('customer')->lockForUpdate()->findOrFail($account->id);
                $first = $a->email_verified_at === null;
                DB::table('customer_account')->where('id', Ids::toBinary($a->id))->update(['login_email' => $row->email, 'email_verified_at' => $now->format(self::FMT)]);
                DB::table('customer')->where('id', Ids::toBinary($a->customer_id))->update(['email' => $row->email]);
                CustomerAuthService::announceEmailVerified($a->customer_id, $row->email, $a->customer->organization_id, 'email_change');
                Audit::record('customer.email.change', 'Customer', $a->customer_id, null, ['previouslyVerified' => ! $first], organizationId: $a->customer->organization_id, siteId: Tenant::siteId());
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::conflict('email_in_use', 'That email address was just taken by another account.');
        }

        return $this->present($customerId);
    }

    public function setPassword(string $customerId, string $password, ?string $current, ?string $currentAccessToken, ?string $ip): void
    {
        DB::transaction(function () use ($customerId, $password, $current, $currentAccessToken, $ip) {
            $a = CustomerAccount::query()->with('customer')->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            if ($a->password_hash !== null) {
                if ($current === null || ! Hash::check($current, $a->password_hash)) {
                    throw ApiProblem::unprocessable('invalid_current_password', 'The current password is wrong.');
                }
            } elseif ($a->login_email === null || $a->email_verified_at === null) {
                throw ApiProblem::conflict('email_not_verified', 'Add and verify your email address before setting a password.');
            }
            $now = now('UTC')->format(self::FMT);
            DB::table('customer_account')->where('id', Ids::toBinary($a->id))->update(['password_hash' => Hash::make($password), 'password_changed_at' => $now, 'failed_login_count' => 0, 'locked_until' => null]);
            $q = DB::table('customer_session')->where('customer_account_id', Ids::toBinary($a->id))->whereNull('revoked_at');
            if ($currentAccessToken !== null) {
                $q->where('access_token_hash', '!=', hash('sha256', $currentAccessToken));
            }
            $q->update(['revoked_at' => $now, 'revoked_reason' => 'password_changed']);
            Audit::record('customer.password.set', 'Customer', $a->customer_id, null, ['first' => $a->password_hash === null, 'ip' => $ip], organizationId: $a->customer->organization_id);
        });
    }

    private static function codeHash(string $code, string $rowId): string
    {
        return hash_hmac('sha256', $code.'|'.bin2hex($rowId), (string) config('app.key'));
    }
}
