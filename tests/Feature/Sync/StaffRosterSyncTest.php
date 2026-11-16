<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\InboxOutcome;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TestData;
use Tests\TwoNodeTestCase;

/** Staff, role grants, PIN/password hashes and cash policy travel between the nodes (StaffRosterApplier). */
class StaffRosterSyncTest extends TwoNodeTestCase
{
    private function receive(array $e): InboxOutcome
    {
        return $this->onNode('cloud', fn () => app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($e)));
    }

    /** A deferred event is retried by the scheduler; do it by hand. */
    private function retry(array $e): InboxOutcome
    {
        return $this->onNode('cloud', fn () => app(InboxProcessor::class)->reprocess($e['eventId']));
    }

    private function staffCreated(string $id, int $version = 1, array $over = []): array
    {
        $e = $this->envelope('StaffRosterUpdated', $id, $version, $over + [
            'staffId' => $id, 'change' => 'CREATED', 'staffNumber' => 'S-0042', 'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => null, 'phone' => null, 'status' => 'ACTIVE',
        ]);
        $e['entityType'] = 'Staff';

        return $e;
    }

    public function test_a_staff_member_created_on_one_node_appears_on_the_other_with_a_login(): void
    {
        $id = Ids::uuid7();
        $e = $this->staffCreated($id);
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($e)->result);
        $this->onNode('cloud', function () use ($id) {
            $s = DB::table('staff')->where('id', Ids::toBinary($id))->first();
            $this->assertSame('S-0042', $s->staff_number);
            $this->assertSame('s-0042', DB::table('user_account')->where('staff_id', Ids::toBinary($id))->value('username'));
        });
        // redelivery of the same event is harmless
        $this->assertSame(InboxOutcome::DUPLICATE, $this->receive($e)->result);
        // and a re-announcement (new event id, same data) changes nothing
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($this->staffCreated($id))->result);
        $this->onNode('cloud', fn () => $this->assertSame(1, DB::table('staff')->where('staff_number', 'S-0042')->count()));
    }

    public function test_a_later_update_and_a_suspension_apply_in_order_and_end_the_sessions(): void
    {
        $id = Ids::uuid7();
        $this->receive($this->staffCreated($id));
        $upd = $this->staffCreated($id, 2, ['change' => 'UPDATED', 'firstName' => 'Adaeze', 'status' => 'SUSPENDED']);
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($upd)->result);
        $this->onNode('cloud', function () use ($id) {
            $s = DB::table('staff')->where('id', Ids::toBinary($id))->first();
            $this->assertSame('Adaeze', $s->first_name);
            $this->assertSame(0, (int) $s->is_active);
        });
        // a version gap waits for its predecessor
        $this->assertSame(InboxOutcome::DEFERRED, $this->receive($this->staffCreated($id, 5, ['change' => 'UPDATED', 'firstName' => 'X']))->result);
    }

    public function test_a_role_grant_is_matched_by_role_code_and_waits_for_the_staff_member(): void
    {
        $id = Ids::uuid7();
        $aid = Ids::uuid7();
        $grant = $this->envelope('StaffRosterUpdated', $aid, 1, [
            'change' => 'GRANTED', 'assignmentId' => $aid, 'staffId' => $id, 'roleCode' => 'WAIT_STAFF', 'scopeLevel' => 'SITE',
            'organizationId' => $this->org, 'siteId' => $this->site, 'facilityUnitId' => null,
        ]);
        $grant['entityType'] = 'RoleAssignment';
        $this->assertSame(InboxOutcome::DEFERRED, $this->receive($grant)->result, 'staff has not arrived yet');

        $this->receive($this->staffCreated($id));
        $this->assertSame(InboxOutcome::APPLIED, $this->retry($grant)->result);
        $this->onNode('cloud', function () use ($id) {
            $codes = DB::table('role_assignment as a')->join('role as r', 'r.id', '=', 'a.role_id')->where('a.staff_id', Ids::toBinary($id))->where('a.is_active', 1)->pluck('r.code')->all();
            $this->assertSame(['WAIT_STAFF'], $codes);
        });

        $revoke = $this->envelope('StaffRosterUpdated', $aid, 2, $grant['payload'] + ['x' => 1]);
        $revoke['entityType'] = 'RoleAssignment';
        $revoke['payload']['change'] = 'REVOKED';
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($revoke)->result);
        $this->onNode('cloud', fn () => $this->assertSame(0, DB::table('role_assignment')->where('staff_id', Ids::toBinary($id))->where('is_active', 1)->count()));
    }

    public function test_a_pin_set_on_one_node_works_on_the_other_and_replaces_the_old_one(): void
    {
        $id = Ids::uuid7();
        $this->receive($this->staffCreated($id));
        foreach (['1234', '5678'] as $i => $pin) {
            $e = $this->envelope('StaffCredentialChanged', $id, 1, ['staffId' => $id, 'credentialType' => 'PIN', 'hash' => Hash::make($pin), 'algorithm' => 'ARGON2ID']);
            $e['entityType'] = 'Staff';
            $this->assertSame(InboxOutcome::APPLIED, $this->receive($e)->result);
        }
        $this->onNode('cloud', function () use ($id) {
            $acc = DB::table('user_account')->where('staff_id', Ids::toBinary($id))->first();
            $active = DB::table('credential')->where('user_account_id', $acc->id)->where('credential_type', 'PIN')->where('is_active', 1)->get();
            $this->assertCount(1, $active);
            $this->assertTrue(Hash::check('5678', $active[0]->credential_hash));
            $this->assertFalse(Hash::check('1234', $active[0]->credential_hash));
        });
    }

    public function test_cash_policy_that_arrives_first_is_applied_when_the_staff_member_lands(): void
    {
        $id = Ids::uuid7();
        $e = $this->envelope('StaffCollectionPolicyChanged', $id, 1, ['staffId' => $id, 'cashHolding' => 'ALLOW', 'cashLimit' => '50000.0000']);
        $e['entityType'] = 'Staff';
        $this->assertSame(InboxOutcome::DEFERRED, $this->receive($e)->result);
        $this->receive($this->staffCreated($id)); // arrival of the staff member releases the waiting policy event by itself
        $this->onNode('cloud', fn () => $this->assertSame('ALLOW', DB::table('staff_collection_policy')->where('staff_id', Ids::toBinary($id))->value('cash_holding')));
    }

    public function test_publish_staff_catches_the_other_node_up_with_everyone_already_here(): void
    {
        $tenant = ['org' => $this->org, 'site' => $this->site];
        $this->onNode('local', function () use ($tenant) {
            $s = TestData::staff($tenant, 'amaka', 'Pass#12345', '4321');
            TestData::assign($s, 'WAIT_STAFF');
            Artisan::call('r007:sync:publish-staff');
        });
        $events = $this->onNode('local', fn () => DB::table('outbox_event')->orderBy('seq')->get());
        $this->assertGreaterThanOrEqual(4, $events->count()); // staff + role + password + pin
        foreach ($events as $row) {
            $env = [
                'eventId' => Ids::fromBinary($row->id), 'eventType' => $row->event_type, 'entityType' => $row->entity_type, 'entityId' => Ids::fromBinary($row->entity_id),
                'entityVersion' => (int) $row->entity_version, 'organizationId' => Ids::fromBinary($row->organization_id), 'siteId' => $row->site_id ? Ids::fromBinary($row->site_id) : null,
                'facilityId' => null, 'sourceNode' => 'local', 'occurredAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'payload' => json_decode($row->payload, true),
            ];
            $this->assertContains($this->receive($env)->result, [InboxOutcome::APPLIED], $row->event_type);
        }
        $this->onNode('cloud', function () {
            $acc = DB::table('user_account')->where('username', 'amaka')->first();
            $this->assertNotNull($acc);
            $pin = DB::table('credential')->where('user_account_id', $acc->id)->where('credential_type', 'PIN')->where('is_active', 1)->first();
            $this->assertTrue(Hash::check('4321', $pin->credential_hash));
            $this->assertSame(1, DB::table('role_assignment as a')->join('role as r', 'r.id', '=', 'a.role_id')->where('a.staff_id', $acc->staff_id)->where('r.code', 'WAIT_STAFF')->count());
        });
    }
}
