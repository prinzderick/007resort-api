<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Appliers\VersionedTarget;
use App\Domain\Sync\Appliers\VersionedTargets;
use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\InboxOutcome;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TwoNodeTestCase;

/** ConfigurationUpdated / StaffRosterUpdated: version-checked apply, conflicts recorded, never last-writer-wins. */
class VersionedApplierTest extends TwoNodeTestCase
{
    private function receive(array $e): InboxOutcome
    {
        return $this->onNode('cloud', fn () => app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($e)));
    }

    private function config(string $fid, int $version, array $changes, string $type = 'ConfigurationUpdated', string $domain = 'facility'): array
    {
        $e = $this->envelope($type, $fid, $version, ['domain' => $domain, 'changes' => $changes], 'local');
        $e['entityType'] = $domain === 'staff' ? 'Staff' : 'FacilityUnit';

        return $e;
    }

    private function facilityRow(string $fid): object
    {
        return $this->onNode('cloud', fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->first());
    }

    public function test_configuration_update_with_the_next_version_is_applied_and_traced_to_its_source(): void
    {
        $fid = $this->facility('cloud', 'spa');
        $e = $this->config($fid, 2, ['name' => 'Serenity Spa', 'isActive' => false]);
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($e)->result);
        $row = $this->facilityRow($fid);
        $this->assertSame('Serenity Spa', $row->name);
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame(2, (int) $row->row_version);

        $this->onNode('cloud', function () use ($e, $fid) {
            $a = DB::table('audit_log')->where('action', 'sync.event.applied')->first();
            $this->assertSame(Ids::toBinary($fid), $a->entity_id);
            $new = json_decode($a->new_value, true);
            $this->assertSame($e['eventId'], $new['eventId']);
            $this->assertSame('local', $new['sourceNode'], 'a synced change is never indistinguishable from a local one');
            $this->assertTrue(Audit::verifyChain()->valid);
        });
    }

    public function test_stale_configuration_version_is_recorded_as_a_conflict_and_never_applied(): void
    {
        $fid = $this->facility('cloud', 'spa');
        $this->onNode('cloud', fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->update(['name' => 'Local edit', 'row_version' => 3]));

        $o = $this->receive($this->config($fid, 3, ['name' => 'Remote edit']));
        $this->assertSame(InboxOutcome::CONFLICT, $o->result);
        $o = $this->receive($this->config($fid, 2, ['name' => 'Older remote edit']));
        $this->assertSame(InboxOutcome::CONFLICT, $o->result);

        $this->assertSame('Local edit', $this->facilityRow($fid)->name);
        $this->assertSame(3, (int) $this->facilityRow($fid)->row_version);
        $this->onNode('cloud', function () {
            $rows = DB::table('sync_conflict')->orderBy('incoming_version')->get();
            $this->assertCount(2, $rows);
            $this->assertSame(['CONFIGURATION', 'CONFIGURATION'], $rows->pluck('category')->all());
            $this->assertSame('Local edit', json_decode($rows[0]->local_payload, true)['name']);
            $this->assertSame('Older remote edit', json_decode($rows[0]->incoming_payload, true)['changes']['name']);
        });
    }

    public function test_a_version_gap_is_deferred_and_applies_in_order_once_the_missing_change_arrives(): void
    {
        $fid = $this->facility('cloud', 'spa');
        $this->assertSame(InboxOutcome::DEFERRED, $this->receive($this->config($fid, 3, ['name' => 'Third']))->result);
        $this->assertSame('spa', strtolower($this->facilityRow($fid)->name), 'untouched while the gap is open');
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($this->config($fid, 2, ['name' => 'Second']))->result);
        $row = $this->facilityRow($fid);
        $this->assertSame('Third', $row->name, 'v2 then the deferred v3');
        $this->assertSame(3, (int) $row->row_version);
    }

    public function test_an_update_for_an_entity_that_does_not_exist_yet_is_deferred_not_lost(): void
    {
        $o = $this->receive($this->config(Ids::uuid7(), 2, ['name' => 'Ghost']));
        $this->assertSame(InboxOutcome::DEFERRED, $o->result);
        $this->onNode('cloud', fn () => $this->assertSame(1, DB::table('inbox_event')->where('result', 'PENDING')->count()));
    }

    public function test_staff_roster_updates_are_version_checked_with_permission_conflicts(): void
    {
        $staff = $this->onNode('cloud', fn () => TestData::staff(['org' => $this->org, 'site' => $this->site], 'amaka'));
        $ok = $this->config($staff->id, 2, ['firstName' => 'Amara', 'isActive' => false], 'StaffRosterUpdated', 'staff');
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($ok)->result);
        $row = $this->onNode('cloud', fn () => DB::table('staff')->where('id', Ids::toBinary($staff->id))->first());
        $this->assertSame('Amara', $row->first_name);
        $this->assertSame(0, (int) $row->is_active);

        $stale = $this->config($staff->id, 2, ['firstName' => 'Someone Else'], 'StaffRosterUpdated', 'staff');
        $this->assertSame(InboxOutcome::CONFLICT, $this->receive($stale)->result);
        $this->onNode('cloud', function () use ($staff) {
            $this->assertSame('Amara', DB::table('staff')->where('id', Ids::toBinary($staff->id))->value('first_name'));
            $this->assertSame('PERMISSION', DB::table('sync_conflict')->value('category'));
        });
    }

    public function test_only_declared_fields_can_change_and_domains_cannot_cross_event_types(): void
    {
        $fid = $this->facility('cloud', 'spa');
        $staff = $this->onNode('cloud', fn () => TestData::staff(['org' => $this->org, 'site' => $this->site], 'ngozi'));

        // organizationId is not a syncable column of facility.
        $o = $this->receive($this->config($fid, 2, ['organizationId' => Ids::uuid7()]));
        $this->assertSame(InboxOutcome::FAILED, $o->result);
        $this->assertStringContainsString('not a syncable field', (string) $o->detail);
        // A configuration event may not rewrite the staff roster, nor a roster event the configuration.
        $this->assertSame(InboxOutcome::FAILED, $this->receive($this->config($staff->id, 2, ['firstName' => 'X'], 'ConfigurationUpdated', 'staff'))->result);
        $this->assertSame(InboxOutcome::FAILED, $this->receive($this->config($fid, 2, ['name' => 'X'], 'StaffRosterUpdated', 'facility'))->result);
        $this->assertSame(InboxOutcome::FAILED, $this->receive($this->config($fid, 2, ['name' => 'X'], 'ConfigurationUpdated', 'nope'))->result);

        $this->assertSame(1, (int) $this->facilityRow($fid)->row_version);
        $this->assertSame('Spa', $this->facilityRow($fid)->name);
    }

    public function test_other_modules_register_their_own_versioned_targets(): void
    {
        $this->app->make(VersionedTargets::class)->register('facilityCode', new VersionedTarget('facility_unit', 'CONFIGURATION', ['code' => ['code', 'string']]));
        $fid = $this->facility('cloud', 'gym');
        $this->assertSame(InboxOutcome::APPLIED, $this->receive($this->config($fid, 2, ['code' => 'gym-2'], 'ConfigurationUpdated', 'facilityCode'))->result);
        $this->assertSame('gym-2', $this->facilityRow($fid)->code);
    }
}
