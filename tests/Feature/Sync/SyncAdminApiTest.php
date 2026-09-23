<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Services\HeartbeatService;
use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderedProbeApplier;
use Tests\Support\PoisonApplier;
use Tests\Support\TestData;
use Tests\TwoNodeTestCase;

/** IT/Admin surface on the Cloud node: status, conflicts, failed outbox/inbox, retry/reprocess/resolve (audited). */
class SyncAdminApiTest extends TwoNodeTestCase
{
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useNode('cloud');
        $t = ['org' => $this->org, 'site' => $this->site];
        $admin = TestData::staff($t, 'itadmin');
        TestData::assign($admin, 'IT_ADMIN');
        $this->adminId = $admin->id;
        TestData::assign(TestData::staff($t, 'waiter'), 'WAIT_STAFF');
    }

    private function as(string $user): static
    {
        $token = $this->postJson('/api/v1/auth/staff/login', ['username' => $user, 'password' => TestData::PASSWORD])->assertOk()->json('accessToken');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => Ids::uuid7()];
    }

    private function conflict(): string
    {
        $fid = $this->facility('cloud', 'bar');
        DB::table('facility_unit')->where('id', Ids::toBinary($fid))->update(['row_version' => 2, 'name' => 'On-site edit']);
        $e = $this->envelope('ConfigurationUpdated', $fid, 2, ['domain' => 'facility', 'changes' => ['name' => 'Remote']]);
        $e['entityType'] = 'FacilityUnit';
        app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($e));

        return Ids::fromBinary(DB::table('sync_conflict')->orderByDesc('id')->value('id'));
    }

    public function test_endpoints_require_the_config_manage_permission(): void
    {
        $this->getJson('/api/v1/sync/status')->assertStatus(401);
        $this->as('waiter')->getJson('/api/v1/sync/status')->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->as('waiter')->getJson('/api/v1/sync/conflicts')->assertStatus(403);
        $this->as('waiter')->postJson('/api/v1/sync/outbox/replay-failed', [], $this->idem())->assertStatus(403);
        $this->as('itadmin')->getJson('/api/v1/sync/status')->assertOk();
    }

    public function test_status_reports_health_backlog_and_site_freshness(): void
    {
        $this->conflict();
        $this->onNode('local', fn () => app(HeartbeatService::class)->send());
        $r = $this->as('itadmin')->getJson('/api/v1/sync/status')->assertOk();
        $r->assertJsonStructure(['node', 'peerReachable', 'lastHeartbeatAt', 'lastPeerHeartbeatAt', 'outbox' => ['queued', 'failed', 'conflict', 'oldestQueuedAt'], 'inbox' => ['conflict', 'deferred'], 'health']);
        $this->assertSame('cloud', $r->json('node'));
        $this->assertTrue($r->json('peerReachable'));
        $this->assertSame(1, $r->json('openConflicts'));
        $this->assertSame('DEGRADED', $r->json('health'), 'an open conflict is visible in health');
        $this->assertSame('ONLINE', $r->json('sites.0.status'));
        $this->assertSame($this->site, $r->json('sites.0.siteId'));
    }

    public function test_conflicts_can_be_listed_filtered_inspected_and_resolved_with_an_audit_trail(): void
    {
        $id = $this->conflict();
        $api = $this->as('itadmin');

        $api->getJson('/api/v1/sync/conflicts?status=OPEN&category=CONFIGURATION')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.localPayload.name', 'On-site edit')
            ->assertJsonPath('data.0.incomingPayload.changes.name', 'Remote')->assertJsonPath('page.hasMore', false);
        $api->getJson('/api/v1/sync/conflicts?category=PERMISSION')->assertOk()->assertJsonCount(0, 'data');
        $api->getJson('/api/v1/sync/conflicts?status=BOGUS')->assertStatus(422);
        $api->getJson("/api/v1/sync/conflicts/{$id}")->assertOk()->assertJsonPath('category', 'CONFIGURATION');
        $api->getJson('/api/v1/sync/conflicts/'.Ids::uuid7())->assertStatus(404)->assertJsonPath('code', 'conflict_not_found');

        $api->postJson("/api/v1/sync/conflicts/{$id}/resolve", ['resolution' => 'KEEP_LOCAL', 'note' => 'Manager confirmed on-site price'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
        $api->postJson("/api/v1/sync/conflicts/{$id}/resolve", ['resolution' => 'ACCEPT_INCOMING', 'note' => 'nope'], $this->idem())->assertStatus(422);
        $r = $api->postJson("/api/v1/sync/conflicts/{$id}/resolve", ['resolution' => 'KEEP_LOCAL', 'note' => 'Manager confirmed on-site price'], $this->idem())->assertOk();
        $this->assertSame('RESOLVED', $r->json('status'));
        $this->assertSame($this->adminId, $r->json('resolvedByStaffId'));

        $audit = DB::table('audit_log')->where('action', 'sync.conflict.resolve')->first();
        $this->assertNotNull($audit);
        $this->assertSame(Ids::toBinary($this->adminId), $audit->actor_staff_id);
        $this->assertSame('KEEP_LOCAL', json_decode($audit->new_value, true)['resolution']);
        $this->assertTrue(Audit::verifyChain()->valid);

        $api->postJson("/api/v1/sync/conflicts/{$id}/resolve", ['resolution' => 'DISMISSED', 'note' => 'again'], $this->idem())->assertStatus(409)->assertJsonPath('code', 'conflict_already_resolved');
        $this->assertSame(0, DB::table('audit_log')->where('action', 'sync.event.applied')->count(), 'resolving never force-applies the incoming change');
    }

    public function test_reprocessing_a_conflict_applies_it_once_the_cause_is_gone_and_auto_resolves(): void
    {
        $this->applierRegistry()->register(['OrderCancelled', 'OrderCreated'], new OrderedProbeApplier);
        $inbox = app(InboxProcessor::class);
        $blocked = Ids::uuid7();
        $healed = Ids::uuid7();
        foreach ([$blocked, $healed] as $order) {
            $inbox->receive(InboundEvent::fromEnvelope($this->envelope('OrderCancelled', $order, 2, [])));
        }
        $this->travel(901)->seconds();
        $inbox->reprocessDue(); // both gaps outlive the escalation window -> ENTITY_VERSION conflicts
        $this->assertSame(2, DB::table('sync_conflict')->where('category', 'ENTITY_VERSION')->count());
        $conflictOf = fn (string $order) => Ids::fromBinary(DB::table('sync_conflict')->where('entity_id', Ids::toBinary($order))->value('id'));
        $api = $this->as('itadmin');

        // Cause still present (v1 never arrived): reprocess defers again and the conflict stays OPEN.
        $api->postJson('/api/v1/sync/conflicts/'.$conflictOf($blocked).'/reprocess', [], $this->idem())->assertOk()
            ->assertJsonPath('outcome.result', 'DEFERRED')->assertJsonPath('conflict.status', 'OPEN');

        // v1 finally arrives for the other order; reprocessing the conflict now applies v2 and auto-resolves it.
        $inbox->receive(InboundEvent::fromEnvelope($this->envelope('OrderCreated', $healed, 1, [])));
        $r = $api->postJson('/api/v1/sync/conflicts/'.$conflictOf($healed).'/reprocess', [], $this->idem())->assertOk();
        $this->assertSame('APPLIED', $r->json('outcome.result'));
        $this->assertSame('RESOLVED', $r->json('conflict.status'));
        $this->assertSame('REPROCESSED', $r->json('conflict.resolution'));
        $this->assertSame(2, DB::table('sync_probe')->where('entity_id', $healed)->count());
        $this->assertSame(2, DB::table('audit_log')->where('action', 'sync.inbox.reprocess')->count());
        $api->postJson('/api/v1/sync/conflicts/'.$conflictOf($healed).'/reprocess', [], $this->idem())->assertStatus(409);
    }

    public function test_failed_outbox_events_can_be_listed_retried_and_replayed_with_audit(): void
    {
        $failed = $this->emit('cloud', 'Fine', 'Thing', Ids::uuid7(), []);
        $failed2 = $this->emit('cloud', 'Fine', 'Thing', Ids::uuid7(), []);
        $queued = $this->emit('cloud', 'Fine', 'Thing', Ids::uuid7(), []);
        DB::table('outbox_event')->whereIn('id', [Ids::toBinary($failed), Ids::toBinary($failed2)])->update(['sync_status' => 'FAILED', 'retry_count' => 8, 'last_error' => json_encode(['result' => 'FAILED', 'message' => 'boom'])]);
        $api = $this->as('itadmin');

        $api->getJson('/api/v1/sync/outbox?status=FAILED')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.lastError.message', 'boom');
        $api->postJson("/api/v1/sync/outbox/{$failed}/retry", [], $this->idem())->assertOk()->assertJsonPath('syncStatus', 'QUEUED');
        $row = DB::table('outbox_event')->where('id', Ids::toBinary($failed))->first();
        $this->assertSame(['QUEUED', 0], [$row->sync_status, (int) $row->retry_count]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'sync.outbox.retry')->count());

        $api->postJson("/api/v1/sync/outbox/{$queued}/retry", [], $this->idem())->assertStatus(409)->assertJsonPath('code', 'outbox_event_not_retryable');
        $api->postJson('/api/v1/sync/outbox/'.Ids::uuid7().'/retry', [], $this->idem())->assertStatus(404);

        $api->postJson('/api/v1/sync/outbox/replay-failed', [], $this->idem())->assertOk()->assertJsonPath('outboxRequeued', 1);
        $this->assertSame(0, DB::table('outbox_event')->where('sync_status', 'FAILED')->count());
    }

    public function test_failed_inbox_events_can_be_listed_and_reprocessed(): void
    {
        $e = $this->envelope('Poison', Ids::uuid7(), 1, []);
        $this->applierRegistry()->register('Poison', new PoisonApplier);
        app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($e));
        $api = $this->as('itadmin');
        $api->getJson('/api/v1/sync/inbox-events?result=FAILED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.eventId', $e['eventId'])
            ->assertJsonPath('data.0.lastError.message', fn ($m) => str_contains($m, 'boom'));
        $api->postJson('/api/v1/sync/inbox-events/'.$e['eventId'].'/reprocess', [], $this->idem())->assertOk()->assertJsonPath('result', 'FAILED');
        $this->assertSame(2, (int) DB::table('inbox_event')->value('attempts'));
    }
}
