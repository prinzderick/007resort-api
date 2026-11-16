<?php

namespace App\Domain\Sync\Console;

use App\Support\Ids;
use App\Support\Sync\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-announce the whole staff roster (staff, role grants, PIN/password/card hashes, cash policies) so the other node
 * can catch up. Safe to run any time and on either node: the receiver ignores what it already has.
 */
class SyncPublishStaffCommand extends Command
{
    protected $signature = 'r007:sync:publish-staff';

    protected $description = 'Queue sync events for every staff member, role assignment, credential and cash policy so the peer can catch up';

    public function handle(): int
    {
        $n = ['staff' => 0, 'roles' => 0, 'credentials' => 0, 'policies' => 0];
        DB::transaction(function () use (&$n): void {
            foreach (DB::table('staff')->orderBy('created_at')->get() as $s) {
                $acc = DB::table('user_account')->where('staff_id', $s->id)->first();
                $status = $s->deleted_at !== null ? 'TERMINATED' : ($s->is_active ? 'ACTIVE' : 'SUSPENDED');
                $sid = Ids::fromBinary($s->id);
                Outbox::record('StaffRosterUpdated', 'Staff', $sid, [
                    'staffId' => $sid, 'change' => 'CREATED', 'username' => $acc?->username, 'accountId' => $acc ? Ids::fromBinary($acc->id) : null,
                    'staffNumber' => $s->staff_number, 'firstName' => $s->first_name, 'lastName' => $s->last_name, 'email' => $s->email, 'phone' => $s->phone,
                    'status' => $status, 'rowVersion' => (int) $s->row_version,
                ], (int) $s->row_version, Ids::fromBinary($s->organization_id), Ids::fromBinary($s->site_id));
                $n['staff']++;

                foreach (DB::table('role_assignment as a')->join('role as r', 'r.id', '=', 'a.role_id')->where('a.staff_id', $s->id)->where('a.is_active', 1)->whereNull('a.deleted_at')
                    ->get(['a.*', 'r.code as role_code']) as $a) {
                    Outbox::record('StaffRosterUpdated', 'RoleAssignment', Ids::fromBinary($a->id), [
                        'change' => 'GRANTED', 'assignmentId' => Ids::fromBinary($a->id), 'staffId' => $sid, 'roleCode' => $a->role_code, 'scopeLevel' => $a->scope_level,
                        'organizationId' => Ids::fromBinary($a->organization_id), 'siteId' => $a->site_id ? Ids::fromBinary($a->site_id) : null,
                        'facilityUnitId' => $a->facility_unit_id ? Ids::fromBinary($a->facility_unit_id) : null,
                    ], 1, facilityId: $a->facility_unit_id ? Ids::fromBinary($a->facility_unit_id) : null);
                    $n['roles']++;
                }
                if ($acc !== null) {
                    foreach (DB::table('credential')->where('user_account_id', $acc->id)->where('is_active', 1)->whereIn('credential_type', ['PASSWORD', 'PIN', 'NFC_CARD'])->get() as $c) {
                        Outbox::record('StaffCredentialChanged', 'Staff', $sid, [
                            'staffId' => $sid, 'credentialType' => $c->credential_type, 'hash' => $c->credential_hash, 'algorithm' => $c->algorithm,
                        ], (int) $s->row_version);
                        $n['credentials']++;
                    }
                }
                if ($p = DB::table('staff_collection_policy')->where('staff_id', $s->id)->first()) {
                    Outbox::record('StaffCollectionPolicyChanged', 'Staff', $sid, [
                        'staffId' => $sid, 'cashHolding' => $p->cash_holding, 'cashLimit' => $p->cash_limit,
                    ], (int) $p->row_version);
                    $n['policies']++;
                }
            }
        });
        $this->info("Queued: {$n['staff']} staff, {$n['roles']} role grants, {$n['credentials']} credentials, {$n['policies']} cash policies.");

        return self::SUCCESS;
    }
}
