<?php

namespace App\Domain\Sync\Appliers;

use App\Domain\Identity\Services\SessionRevoker;
use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Staff roster sync (both directions, same code on both nodes). Handles:
 *  - StaffRosterUpdated / Staff           CREATED | UPDATED   -> staff + user_account
 *  - StaffRosterUpdated / RoleAssignment  GRANTED | REVOKED   -> role_assignment (role matched by CODE: role ids differ per node)
 *  - StaffCredentialChanged               PASSWORD | PIN | NFC_CARD hash (never a plain secret) -> credential
 *  - StaffCollectionPolicyChanged         -> staff_collection_policy
 * A StaffRosterUpdated that names a `domain` is the older versioned-change shape and goes to VersionedDomainApplier.
 * Idempotent: replays are no-ops. A change for a staff member that has not arrived yet is DEFERRED (retried).
 */
final class StaffRosterApplier implements SyncApplier
{
    private const FMT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly VersionedDomainApplier $versioned, private readonly SessionRevoker $sessions) {}

    public function apply(InboundEvent $e): ApplyResult
    {
        return match ($e->eventType) {
            'StaffRosterUpdated' => isset($e->payload['domain']) ? $this->versioned->apply($e) : match ($e->entityType) {
                'Staff' => $this->staff($e),
                'RoleAssignment' => $this->assignment($e),
                default => throw new InvalidArgumentException("StaffRosterUpdated for unknown entity type '{$e->entityType}'."),
            },
            'StaffCredentialChanged' => $this->credential($e),
            'StaffCollectionPolicyChanged' => $this->policy($e),
            default => throw new InvalidArgumentException("StaffRosterApplier cannot apply {$e->eventType}."),
        };
    }

    private function staff(InboundEvent $e): ApplyResult
    {
        $p = $e->payload;
        $id = Ids::toBinary($e->entityId);
        $row = DB::table('staff')->where('id', $id)->first();
        $status = (string) ($p['status'] ?? 'ACTIVE');
        $attrs = [
            'first_name' => (string) ($p['firstName'] ?? ''), 'last_name' => (string) ($p['lastName'] ?? ''),
            'email' => $p['email'] ?? null, 'phone' => $p['phone'] ?? null,
            'is_active' => $status === 'ACTIVE' ? 1 : 0,
            'deleted_at' => $status === 'TERMINATED' ? now('UTC')->format(self::FMT) : null,
        ];

        if ($row === null) {
            if (($p['change'] ?? 'CREATED') !== 'CREATED' && $e->entityVersion > 1) {
                return ApplyResult::deferred('Staff member has not arrived yet.');
            }
            if ($e->organizationId === null || $e->siteId === null) {
                throw new InvalidArgumentException('Staff event without organization/site.');
            }
            $number = (string) ($p['staffNumber'] ?? throw new InvalidArgumentException('Staff event without staffNumber.'));
            DB::table('staff')->insert($attrs + [
                'id' => $id, 'organization_id' => Ids::toBinary($e->organizationId), 'site_id' => Ids::toBinary($e->siteId),
                'staff_number' => $number, 'row_version' => max(1, $e->entityVersion),
            ]);
            $username = strtolower((string) ($p['username'] ?? preg_replace('/\s+/', '', $number)));
            if (! DB::table('user_account')->where('staff_id', $id)->exists()) {
                DB::table('user_account')->insert([
                    'id' => Ids::toBinary((string) ($p['accountId'] ?? Ids::uuid7())), 'staff_id' => $id, 'username' => $username,
                    'is_active' => $status === 'ACTIVE' ? 1 : 0,
                ]);
            }

            return ApplyResult::applied('staff created');
        }

        $local = (int) $row->row_version;
        if ($e->entityVersion === $local && $this->same($row, $attrs)) {
            return ApplyResult::applied('already applied');
        }
        if ($e->entityVersion <= $local) {
            return ApplyResult::conflict('PERMISSION', $local, $e->entityVersion, (array) $row, 'staff record already at version '.$local);
        }
        if ($e->entityVersion > $local + 1) {
            return ApplyResult::deferred('Waiting for staff version '.($local + 1));
        }
        DB::table('staff')->where('id', $id)->update($attrs + ['row_version' => $e->entityVersion]);
        DB::table('user_account')->where('staff_id', $id)->update(['is_active' => $status === 'ACTIVE' ? 1 : 0]);
        if ($status !== 'ACTIVE' && ($acc = DB::table('user_account')->where('staff_id', $id)->first())) {
            $this->sessions->revokeAccount(Ids::fromBinary($acc->id), 'staff_'.strtolower($status));
        }

        return ApplyResult::applied('staff updated');
    }

    private function assignment(InboundEvent $e): ApplyResult
    {
        $p = $e->payload;
        $aid = Ids::toBinary((string) ($p['assignmentId'] ?? $e->entityId));
        $staffId = (string) ($p['staffId'] ?? throw new InvalidArgumentException('Role event without staffId.'));
        if (! DB::table('staff')->where('id', Ids::toBinary($staffId))->exists()) {
            return ApplyResult::deferred('Staff member has not arrived yet.');
        }
        $existing = DB::table('role_assignment')->where('id', $aid)->first();

        if (($p['change'] ?? '') === 'REVOKED') {
            if ($existing !== null && (int) $existing->is_active === 1) {
                DB::table('role_assignment')->where('id', $aid)->update(['is_active' => 0, 'deleted_at' => now('UTC')->format(self::FMT), 'row_version' => max($e->entityVersion, (int) $existing->row_version + 1)]);
            }

            return ApplyResult::applied('assignment revoked');
        }
        if ($existing !== null) {
            return ApplyResult::applied('already applied');
        }
        $role = DB::table('role')->where('code', (string) ($p['roleCode'] ?? ''))->first();
        if ($role === null) {
            throw new InvalidArgumentException("Unknown role code '".($p['roleCode'] ?? '')."'.");
        }
        $b = fn ($v) => $v === null ? null : Ids::toBinary((string) $v);
        // same person, same role, same scope already active here (granted separately on this node): keep it, do not duplicate
        $dupe = DB::table('role_assignment')->where('staff_id', $b($staffId))->where('role_id', $role->id)->where('scope_level', $p['scopeLevel'])->where('is_active', 1)
            ->where('organization_id', $b($p['organizationId'] ?? $e->organizationId))
            ->when($p['siteId'] ?? null, fn ($q, $v) => $q->where('site_id', $b($v)), fn ($q) => $q->whereNull('site_id'))
            ->when($p['facilityUnitId'] ?? null, fn ($q, $v) => $q->where('facility_unit_id', $b($v)), fn ($q) => $q->whereNull('facility_unit_id'))->exists();
        if ($dupe) {
            return ApplyResult::applied('equivalent assignment already present');
        }
        DB::table('role_assignment')->insert([
            'id' => $aid, 'staff_id' => $b($staffId), 'role_id' => $role->id, 'scope_level' => (string) $p['scopeLevel'],
            'organization_id' => $b($p['organizationId'] ?? $e->organizationId), 'site_id' => $b($p['siteId'] ?? null), 'facility_unit_id' => $b($p['facilityUnitId'] ?? null),
            'granted_at' => now('UTC')->format(self::FMT), 'row_version' => 1,
        ]);

        return ApplyResult::applied('assignment granted');
    }

    private function credential(InboundEvent $e): ApplyResult
    {
        $p = $e->payload;
        $staff = Ids::toBinary((string) ($p['staffId'] ?? $e->entityId));
        $account = DB::table('user_account')->where('staff_id', $staff)->first();
        if ($account === null) {
            return ApplyResult::deferred('Staff member has not arrived yet.');
        }
        $type = (string) ($p['credentialType'] ?? '');
        if (! in_array($type, ['PASSWORD', 'PIN', 'NFC_CARD'], true)) {
            throw new InvalidArgumentException("Unknown credential type '$type'.");
        }
        $active = fn () => DB::table('credential')->where('user_account_id', $account->id)->where('credential_type', $type)->where('is_active', 1);
        $hash = $p['hash'] ?? null;
        if ($hash === null) { // card removed
            $active()->update(['is_active' => 0]);
        } else {
            $cur = $active()->first();
            if ($cur !== null && hash_equals((string) $cur->credential_hash, (string) $hash)) {
                return ApplyResult::applied('already applied');
            }
            $active()->update(['is_active' => 0]);
            DB::table('credential')->insert([
                'id' => Ids::toBinary(Ids::uuid7()), 'user_account_id' => $account->id, 'credential_type' => $type,
                'credential_hash' => (string) $hash, 'algorithm' => (string) ($p['algorithm'] ?? 'ARGON2ID'), 'is_active' => 1,
            ]);
        }
        DB::table('user_account')->where('id', $account->id)->update(['failed_login_count' => 0, 'locked_until' => null]);
        $this->sessions->revokeAccount(Ids::fromBinary($account->id), 'credential_reset');

        return ApplyResult::applied('credential updated');
    }

    private function policy(InboundEvent $e): ApplyResult
    {
        $p = $e->payload;
        $staff = Ids::toBinary((string) ($p['staffId'] ?? $e->entityId));
        if (! DB::table('staff')->where('id', $staff)->exists()) {
            return ApplyResult::deferred('Staff member has not arrived yet.');
        }
        $row = DB::table('staff_collection_policy')->where('staff_id', $staff)->first();
        $values = ['cash_holding' => (string) ($p['cashHolding'] ?? 'INHERIT'), 'cash_limit' => $p['cashLimit'] ?? null];
        if ($row === null) {
            DB::table('staff_collection_policy')->insert(['staff_id' => $staff, 'row_version' => max(1, $e->entityVersion)] + $values);
        } elseif ($e->entityVersion > (int) $row->row_version) {
            DB::table('staff_collection_policy')->where('staff_id', $staff)->update($values + ['row_version' => $e->entityVersion]);
        }

        return ApplyResult::applied();
    }

    private function same(object $row, array $attrs): bool
    {
        return $row->first_name === $attrs['first_name'] && $row->last_name === $attrs['last_name'] && $row->email === $attrs['email']
            && $row->phone === $attrs['phone'] && (int) $row->is_active === (int) $attrs['is_active'];
    }
}
