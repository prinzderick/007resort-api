<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Models\UserAccount;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Staff CRUD + credential management (all audited; secrets are hashed, never logged or audited). */
class StaffAdminService
{
    public function __construct(private readonly SessionRevoker $revoker, private readonly PrivilegeGuard $guard) {}

    /** @param  array{staffNumber: string, firstName: string, lastName: string, email?: ?string, phone?: ?string, username?: ?string}  $d */
    public function create(array $d): Staff
    {
        $orgId = RequestContext::organizationId();
        $siteId = RequestContext::siteId();
        $username = strtolower($d['username'] ?? preg_replace('/\s+/', '', $d['staffNumber']));

        try {
            return DB::transaction(function () use ($d, $orgId, $siteId, $username): Staff {
                $staff = Staff::create([
                    'organization_id' => $orgId, 'site_id' => $siteId, 'staff_number' => $d['staffNumber'],
                    'first_name' => $d['firstName'], 'last_name' => $d['lastName'], 'email' => $d['email'] ?? null, 'phone' => $d['phone'] ?? null,
                ]);
                $staff->refresh(); // pick up DB defaults (row_version, timestamps)
                UserAccount::create(['staff_id' => $staff->id, 'username' => $username]);
                Audit::record('staff.create', 'Staff', $staff->id, new: $this->auditView($staff) + ['username' => $username]);
                Outbox::record('StaffRosterUpdated', 'Staff', $staff->id, ['staffId' => $staff->id, 'change' => 'CREATED'] + $this->auditView($staff), (int) $staff->row_version);

                return $staff;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('concurrency_conflict', 'A staff member with that staff number or username already exists.');
            }
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $changes  firstName,lastName,email,phone,status */
    public function update(Staff $staff, array $changes): Staff
    {
        $actor = RequestContext::staffId();
        $this->guard->assertCanManageStaff($actor, $staff->id);

        return DB::transaction(function () use ($staff, $changes, $actor): Staff {
            $locked = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->row_version !== (int) $staff->row_version) {
                throw ApiProblem::concurrencyConflict('The staff record was modified since you read it.');
            }
            $old = $this->auditView($locked);
            $attrs = [];
            foreach (['firstName' => 'first_name', 'lastName' => 'last_name', 'email' => 'email', 'phone' => 'phone'] as $api => $col) {
                if (array_key_exists($api, $changes)) {
                    $attrs[$col] = $changes[$api];
                }
            }
            if (isset($changes['status'])) {
                if ($locked->id === $actor && $changes['status'] !== 'ACTIVE') {
                    throw ApiProblem::conflict('concurrency_conflict', 'You cannot suspend or terminate your own account.');
                }
                $attrs['is_active'] = $changes['status'] === 'ACTIVE' ? 1 : 0;
                $attrs['deleted_at'] = $changes['status'] === 'TERMINATED' ? now('UTC')->format('Y-m-d H:i:s.u') : null;
            }
            $attrs['row_version'] = $locked->row_version + 1;
            $locked->forceFill($attrs)->save();
            $locked->refresh();

            if (isset($changes['status']) && $changes['status'] !== 'ACTIVE' && ($account = UserAccount::query()->where('staff_id', $locked->id)->first())) {
                $this->revoker->revokeAccount($account->id, 'staff_'.strtolower($changes['status']));
            }
            Audit::record('staff.update', 'Staff', $locked->id, old: $old, new: $this->auditView($locked));
            Outbox::record('StaffRosterUpdated', 'Staff', $locked->id, ['staffId' => $locked->id, 'change' => 'UPDATED'] + $this->auditView($locked), (int) $locked->row_version);

            return $locked;
        });
    }

    /** Set (rotate) the PASSWORD or PIN of a staff member. Old credentials of that type are deactivated; sessions revoked; lockout cleared. */
    public function setSecret(Staff $staff, string $type, string $secret): void
    {
        $this->guard->assertCanManageStaff(RequestContext::staffId(), $staff->id);
        $account = $this->account($staff);

        DB::transaction(function () use ($account, $staff, $type, $secret): void {
            Credential::query()->where('user_account_id', $account->id)->where('credential_type', $type)->where('is_active', 1)->update(['is_active' => 0]);
            Credential::create(['user_account_id' => $account->id, 'credential_type' => $type, 'credential_hash' => Hash::make($secret), 'algorithm' => 'ARGON2ID']);
            $account->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
            $this->revoker->revokeAccount($account->id, 'credential_reset');
            Audit::record('credential.set', 'Staff', $staff->id, new: ['staffId' => $staff->id, 'credentialType' => $type]); // never the secret
        });
    }

    /** Register (or replace) the staff member's NFC card. The uid must not already belong to another active account. */
    public function registerCard(Staff $staff, string $cardUid): void
    {
        $this->guard->assertCanManageStaff(RequestContext::staffId(), $staff->id);
        $account = $this->account($staff);
        $hash = StaffAuthService::cardHash($cardUid);
        if ($hash === StaffAuthService::cardHash('')) {
            throw ApiProblem::unprocessable('validation_failed', 'Invalid card uid.', ['cardUid' => ['Invalid card uid.']]);
        }

        DB::transaction(function () use ($account, $staff, $hash): void {
            $existing = Credential::query()->where('credential_type', Credential::NFC_CARD)->where('is_active', 1)->where('credential_hash', $hash)->lockForUpdate()->first();
            if ($existing !== null && $existing->user_account_id !== $account->id) {
                throw ApiProblem::conflict('concurrency_conflict', 'That card is already registered to another staff member.');
            }
            if ($existing === null) {
                Credential::query()->where('user_account_id', $account->id)->where('credential_type', Credential::NFC_CARD)->where('is_active', 1)->update(['is_active' => 0]);
                Credential::create(['user_account_id' => $account->id, 'credential_type' => Credential::NFC_CARD, 'credential_hash' => $hash, 'algorithm' => 'SHA256']);
            }
            Audit::record('credential.nfc_card.register', 'Staff', $staff->id, new: ['staffId' => $staff->id, 'credentialType' => Credential::NFC_CARD]);
        });
    }

    public function removeCard(Staff $staff): void
    {
        $this->guard->assertCanManageStaff(RequestContext::staffId(), $staff->id);
        $account = $this->account($staff);
        DB::transaction(function () use ($account, $staff): void {
            Credential::query()->where('user_account_id', $account->id)->where('credential_type', Credential::NFC_CARD)->where('is_active', 1)->update(['is_active' => 0]);
            Audit::record('credential.nfc_card.remove', 'Staff', $staff->id, new: ['staffId' => $staff->id]);
        });
    }

    /** @return array{hasPassword: bool, hasPin: bool, hasNfcCard: bool} */
    public function credentialSummary(Staff $staff): array
    {
        $types = DB::table('credential as c')->join('user_account as a', 'a.id', '=', 'c.user_account_id')
            ->where('a.staff_id', Ids::toBinary($staff->id))->where('c.is_active', 1)->pluck('c.credential_type')->all();

        return ['hasPassword' => in_array('PASSWORD', $types, true), 'hasPin' => in_array('PIN', $types, true), 'hasNfcCard' => in_array('NFC_CARD', $types, true)];
    }

    private function account(Staff $staff): UserAccount
    {
        return UserAccount::query()->where('staff_id', $staff->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function auditView(Staff $s): array
    {
        return ['staffNumber' => $s->staff_number, 'firstName' => $s->first_name, 'lastName' => $s->last_name, 'email' => $s->email, 'phone' => $s->phone, 'status' => $s->status(), 'rowVersion' => (int) $s->row_version];
    }
}
