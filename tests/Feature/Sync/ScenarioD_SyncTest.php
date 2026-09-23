<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Events\SiteHealthChanged;
use App\Domain\Sync\Jobs\PublishOutboxJob;
use App\Domain\Sync\Services\HeartbeatService;
use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Services\OutboxPublisher;
use App\Domain\Sync\Services\Puller;
use App\Domain\Sync\Services\SiteAvailability;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\InboxOutcome;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OrderedProbeApplier;
use Tests\Support\ProbeApplier;
use Tests\TwoNodeTestCase;

/**
 * architecture/18 §3.1 dual-node scenarios D-1 ... D-10 that belong to the sync engine
 * (D-2/D-3 are Booking authority and D-11..D-13 are ticket/payment-provider concerns of other modules).
 */
class ScenarioD_SyncTest extends TwoNodeTestCase
{
    private function outboxStatuses(string $node): array
    {
        return $this->onNode($node, fn () => DB::table('outbox_event')->orderBy('seq')->pluck('sync_status')->all());
    }

    private function probe(string $node): array
    {
        return $this->onNode($node, fn () => DB::table('sync_probe')->orderBy('id')->get(['kind', 'entity_id', 'version'])->map(fn ($r) => [$r->kind, $r->entity_id, (int) $r->version])->all());
    }

    public function test_d1_internet_down_events_queue_in_the_outbox_and_drain_in_order_after_recovery(): void
    {
        $this->applierRegistry()->register(['OrderCreated', 'OrderUpdated'], new ProbeApplier);
        $a = Ids::uuid7();
        $b = Ids::uuid7();
        $this->emit('local', 'OrderCreated', 'Order', $a, ['n' => 1]);
        $this->emit('local', 'OrderCreated', 'Order', $b, ['n' => 2]);
        $this->emit('local', 'OrderUpdated', 'Order', $a, ['n' => 3], 2);

        $this->peer->down = true;
        $report = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertTrue($report['peerDown']);
        $this->assertSame(['QUEUED', 'QUEUED', 'QUEUED'], $this->outboxStatuses('local'), 'nothing lost, nothing marked FAILED by an outage');
        $row = $this->onNode('local', fn () => DB::table('outbox_event')->orderBy('seq')->first());
        $this->assertSame(1, (int) $row->retry_count);
        $this->assertNotNull($row->next_retry_at);
        $this->assertSame('UNREACHABLE', json_decode($row->last_error, true)['result']);
        $this->assertSame(0, $this->onNode('cloud', fn () => DB::table('inbox_event')->count()));

        // Still inside the backoff window: the publisher does not hammer a dead peer.
        $calls = count($this->peer->calls);
        $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertSame($calls, count($this->peer->calls));

        // Connectivity returns; backoff (capped at 5 min) elapses.
        $this->peer->down = false;
        $this->travel(301)->seconds();
        $report = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertSame(3, $report['synced']);
        $this->assertSame(['SYNCED', 'SYNCED', 'SYNCED'], $this->outboxStatuses('local'));
        $this->assertSame([['OrderCreated', $a, 1], ['OrderCreated', $b, 1], ['OrderUpdated', $a, 2]], $this->probe('cloud'));
    }

    public function test_d4_cloud_retransmits_the_same_event_three_times_local_applies_it_exactly_once(): void
    {
        $fid = Ids::uuid7();
        $this->facility('local', 'restaurant', $fid);
        $this->facility('cloud', 'restaurant', $fid);
        $eventId = $this->emit('cloud', 'ConfigurationUpdated', 'FacilityUnit', $fid, ['domain' => 'facility', 'changes' => ['name' => 'Grand Restaurant']], 2);

        // The acknowledgement never reaches Cloud (retry storm): Cloud re-serves the event after its lease.
        $this->peer->failBefore = fn ($m, $p) => $p === '/sync/pull/ack';
        for ($i = 0; $i < 3; $i++) {
            $r = $this->onNode('local', fn () => app(Puller::class)->run());
            $this->assertSame(1, $r['pulled'], 'delivery #'.($i + 1));
            $this->assertSame($i === 0 ? 1 : 0, $r['applied']);
            $this->assertSame($i === 0 ? 0 : 1, $r['duplicates']);
            $this->travel(61)->seconds(); // lease expires
        }
        $this->assertSame(3, $this->peer->callCount('/sync/pull'));

        $this->onNode('local', function () use ($fid, $eventId) {
            $row = DB::table('facility_unit')->where('id', Ids::toBinary($fid))->first();
            $this->assertSame('Grand Restaurant', $row->name);
            $this->assertSame(2, (int) $row->row_version, 'version advanced once, not three times');
            $this->assertSame(1, DB::table('inbox_event')->where('id', Ids::toBinary($eventId))->count());
            $this->assertSame(1, DB::table('audit_log')->where('action', 'sync.event.applied')->count());
        });
        $this->assertSame(['SYNCING'], $this->outboxStatuses('cloud'), 'served but never acknowledged: still owned by Cloud');

        $this->peer->failBefore = null; // network heals: the ack finally lands
        $this->onNode('local', fn () => app(Puller::class)->run());
        $this->assertSame(['SYNCED'], $this->outboxStatuses('cloud'));
        $this->onNode('local', fn () => $this->assertSame(2, (int) DB::table('facility_unit')->where('id', Ids::toBinary($fid))->value('row_version')));
    }

    public function test_d5_local_retransmits_the_same_payment_event_three_times_cloud_records_exactly_one(): void
    {
        $this->applierRegistry()->register('PaymentCompleted', new ProbeApplier);
        $payment = Ids::uuid7();
        $eventId = $this->emit('local', 'PaymentCompleted', 'Payment', $payment, ['amount' => '1500.0000', 'currency' => 'NGN']);

        // Cloud processes each push but the reply is lost twice, so Local retransmits.
        $this->peer->loseResponse = fn ($m, $p) => $p === '/sync/inbox';
        for ($i = 0; $i < 2; $i++) {
            $r = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
            $this->assertTrue($r['peerDown']);
            $this->assertSame(['QUEUED'], $this->outboxStatuses('local'));
            $this->travel(301)->seconds();
        }
        $this->peer->loseResponse = null;
        $r = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertSame(1, $r['synced']);
        $this->assertSame(3, $this->peer->callCount('/sync/inbox'));

        $this->assertSame([['PaymentCompleted', $payment, 1]], $this->probe('cloud'), 'exactly one payment recorded');
        $this->onNode('cloud', function () use ($eventId) {
            $this->assertSame(1, DB::table('inbox_event')->where('id', Ids::toBinary($eventId))->count());
            $this->assertSame('APPLIED', DB::table('inbox_event')->value('result'));
        });
        $this->assertSame(['SYNCED'], $this->outboxStatuses('local'));
    }

    public function test_d6_out_of_order_events_for_one_entity_are_deferred_then_applied_in_order(): void
    {
        $this->applierRegistry()->register(['OrderCreated', 'OrderCancelled'], new OrderedProbeApplier);
        $order = Ids::uuid7();
        $created = $this->envelope('OrderCreated', $order, 1, ['n' => 1]);
        $cancelled = $this->envelope('OrderCancelled', $order, 2, ['reason' => 'guest left']);
        $receive = fn (array $e) => $this->onNode('cloud', fn () => app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($e)));

        $o = $receive($cancelled);              // arrives FIRST
        $this->assertSame(InboxOutcome::DEFERRED, $o->result);
        $this->assertSame([], $this->probe('cloud'), 'the cancellation must not be applied to a non-existent order');
        $this->onNode('cloud', fn () => $this->assertSame('PENDING', DB::table('inbox_event')->where('id', Ids::toBinary($cancelled['eventId']))->value('result')));

        $o = $receive($created);                // the missing predecessor lands
        $this->assertSame(InboxOutcome::APPLIED, $o->result);
        $this->assertSame([['OrderCreated', $order, 1], ['OrderCancelled', $order, 2]], $this->probe('cloud'), 'applied in entity order, once each');
        $this->onNode('cloud', fn () => $this->assertSame('APPLIED', DB::table('inbox_event')->where('id', Ids::toBinary($cancelled['eventId']))->value('result')));

        $this->assertSame(InboxOutcome::DUPLICATE, $receive($cancelled)->result, 'the sender retries the deferred one: harmless no-op');
        $this->assertCount(2, $this->probe('cloud'));
    }

    public function test_d6_a_gap_that_never_closes_escalates_to_entity_version_conflict(): void
    {
        $this->applierRegistry()->register('OrderCancelled', new OrderedProbeApplier);
        $order = Ids::uuid7();
        $e = $this->envelope('OrderCancelled', $order, 3, []);
        $inbox = fn () => app(InboxProcessor::class);
        $this->assertSame(InboxOutcome::DEFERRED, $this->onNode('cloud', fn () => $inbox()->receive(InboundEvent::fromEnvelope($e)))->result);

        $this->travel(901)->seconds();
        $this->onNode('cloud', function () use ($inbox, $e) {
            $inbox()->reprocessDue();
            $this->assertSame('CONFLICT', DB::table('inbox_event')->where('id', Ids::toBinary($e['eventId']))->value('result'));
            $c = DB::table('sync_conflict')->first();
            $this->assertSame('ENTITY_VERSION', $c->category);
            $this->assertSame('OPEN', $c->status);
            $this->assertSame(3, (int) $c->incoming_version);
            $this->assertSame(0, DB::table('sync_probe')->count());
        });
    }

    public function test_d7_online_payment_while_local_is_disconnected_stays_recorded_at_cloud_and_reaches_local_later(): void
    {
        $this->applierRegistry()->register('OnlinePaymentConfirmed', new ProbeApplier);
        $payment = Ids::uuid7();
        $this->emit('cloud', 'OnlinePaymentConfirmed', 'Payment', $payment, ['amount' => '25000.0000', 'providerEventRef' => 'evt_1']);

        $this->peer->down = true; // Local is unreachable / offline
        $r = $this->onNode('local', fn () => app(Puller::class)->run());
        $this->assertTrue($r['peerDown']);
        $this->assertSame(['LOCAL'], $this->outboxStatuses('cloud'), 'safely queued at Cloud, never lost');

        $this->peer->down = false;
        $r = $this->onNode('local', fn () => app(Puller::class)->run());
        $this->assertSame(1, $r['applied']);
        $this->assertSame([['OnlinePaymentConfirmed', $payment, 1]], $this->probe('local'));
        $this->assertSame(['SYNCED'], $this->outboxStatuses('cloud'));
    }

    public function test_d8_stale_heartbeat_flips_offline_only_after_the_threshold_and_disables_the_gate(): void
    {
        Event::fake([SiteHealthChanged::class]);
        $availability = new class($this)
        {
            public function __construct(private readonly TwoNodeTestCase $t) {}

            public function isLocalFresh(): bool
            {
                return $this->t->onNode('cloud', fn () => app(SiteAvailability::class)->isLocalFresh());
            }

            public function snapshot(): array
            {
                return $this->t->onNode('cloud', fn () => app(SiteAvailability::class)->snapshot());
            }
        };
        $this->assertFalse($availability->isLocalFresh(), 'never heard from Local: not fresh');

        $r = $this->onNode('local', fn () => app(HeartbeatService::class)->send());
        $this->assertTrue($r['ok']);
        $this->onNode('cloud', function () {
            $h = DB::table('site_health')->first();
            $this->assertSame('ONLINE', $h->status);
            $this->assertNotNull($h->last_heartbeat_at);
            $this->assertSame((string) config('node.version'), $h->app_version);
        });
        $this->assertTrue($availability->isLocalFresh());
        Event::assertDispatched(SiteHealthChanged::class, fn ($e) => $e->data['status'] === 'ONLINE' && $e->broadcastAs() === 'site.health');

        // Two heartbeat intervals missed (60s): a single lost packet must NOT degrade anything.
        $this->travel(60)->seconds();
        $this->assertSame(0, $this->onNode('cloud', fn () => app(SiteAvailability::class)->markStale()));
        $this->assertTrue($availability->isLocalFresh());
        $this->onNode('cloud', fn () => $this->assertSame('ONLINE', DB::table('site_health')->value('status')));

        // 3 missed (stale_after = 90s) -> OFFLINE, gate closes even before the scheduler marker runs.
        $this->travel(31)->seconds();
        $this->assertFalse($availability->isLocalFresh(), 'gate is computed from the timestamp, not the marker');
        $this->assertSame(1, $this->onNode('cloud', fn () => app(SiteAvailability::class)->markStale()));
        $this->onNode('cloud', fn () => $this->assertSame('OFFLINE', DB::table('site_health')->value('status')));
        Event::assertDispatched(SiteHealthChanged::class, fn ($e) => $e->data['status'] === 'OFFLINE');
        $this->assertSame('OFFLINE', $availability->snapshot()['status']);

        // Heartbeats resume: automatic recovery, no manual re-enable.
        $this->onNode('local', fn () => app(HeartbeatService::class)->send());
        $this->assertTrue($availability->isLocalFresh());
        $this->onNode('cloud', fn () => $this->assertSame('ONLINE', DB::table('site_health')->value('status')));

        // On the Local node itself the answer is always yes.
        $this->assertTrue($this->onNode('local', fn () => app(SiteAvailability::class)->isLocalFresh()));
    }

    public function test_d9_redis_or_queue_worker_stopped_then_restarted_loses_nothing(): void
    {
        $this->applierRegistry()->register('StockReceived', new ProbeApplier);
        $ids = [Ids::uuid7(), Ids::uuid7()];
        foreach ($ids as $id) {
            $this->emit('local', 'StockReceived', 'StockMovement', $id, ['qty' => '10.0000']);
        }

        // Redis is DOWN when the scheduler tries to enqueue the publisher job.
        config(['database.redis.dead' => ['url' => null, 'host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'password' => null], 'queue.connections.dead' => ['driver' => 'redis', 'connection' => 'dead', 'queue' => 'sync-d9', 'retry_after' => 90]]);
        foreach (['redis', 'queue'] as $svc) {
            $this->app->forgetInstance($svc);
            Facade::clearResolvedInstance($svc);
        }
        $threw = false;
        try {
            $this->onNode('local', fn () => Bus::dispatch((new PublishOutboxJob)->onConnection('dead')));
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'enqueueing fails while Redis is down');
        Cache::flush(); // Redis restarted empty
        $this->assertSame(['LOCAL', 'LOCAL'], $this->outboxStatuses('local'), 'committed events are intact in MySQL');
        $this->assertSame([], $this->probe('cloud'));

        // Redis is back; the job sits on the queue while the WORKER is still stopped.
        config(['sync.queue' => 'sync-d9']);
        Queue::connection('redis')->clear('sync-d9');
        $this->onNode('local', fn () => Bus::dispatch((new PublishOutboxJob)->onConnection('redis')));
        $this->assertSame(1, Queue::connection('redis')->size('sync-d9'));
        $this->emit('local', 'StockReceived', 'StockMovement', $late = Ids::uuid7(), ['qty' => '1.0000']); // committed meanwhile
        $this->assertSame([], $this->probe('cloud'));

        // A previous worker died mid-batch, leaving rows SYNCING: they must be recovered, not orphaned.
        $this->onNode('local', fn () => DB::table('outbox_event')->where('entity_id', Ids::toBinary($ids[0]))->update(['sync_status' => 'SYNCING']));

        // Worker restarts and drains the real Redis queue.
        $this->onNode('local', fn () => Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'sync-d9', '--once' => true]));
        $this->assertSame(['SYNCED', 'SYNCED', 'SYNCED'], $this->outboxStatuses('local'));
        $this->assertCount(3, $this->probe('cloud'));
        $this->assertSame(0, Queue::connection('redis')->size('sync-d9'));
    }

    public function test_d10_configuration_edited_on_both_nodes_is_recorded_as_a_conflict_and_never_overwritten(): void
    {
        $fid = Ids::uuid7();
        $this->facility('local', 'bar', $fid);
        $this->facility('cloud', 'bar', $fid);

        // Both sides edit v1 -> v2 independently.
        $this->onNode('local', fn () => DB::table('facility_unit')->where('id', Ids::toBinary($fid))->update(['name' => 'Bar (on-site edit)', 'row_version' => 2]));
        $eventId = $this->emit('cloud', 'ConfigurationUpdated', 'FacilityUnit', $fid, ['domain' => 'facility', 'changes' => ['name' => 'Bar (remote edit)']], 2);

        $r = $this->onNode('local', fn () => app(Puller::class)->run());
        $this->assertSame(1, $r['conflicts']);
        $this->assertSame(0, $r['applied']);

        $this->onNode('local', function () use ($fid, $eventId) {
            $f = DB::table('facility_unit')->where('id', Ids::toBinary($fid))->first();
            $this->assertSame('Bar (on-site edit)', $f->name, 'local value untouched');
            $this->assertSame(2, (int) $f->row_version);
            $c = DB::table('sync_conflict')->first();
            $this->assertSame('CONFIGURATION', $c->category);
            $this->assertSame('OPEN', $c->status);
            $this->assertSame(Ids::toBinary($eventId), $c->event_id);
            $this->assertSame(2, (int) $c->local_version);
            $this->assertSame(2, (int) $c->incoming_version);
            $this->assertSame('Bar (on-site edit)', json_decode($c->local_payload, true)['name']);
            $this->assertSame('Bar (remote edit)', json_decode($c->incoming_payload, true)['changes']['name']);
            $this->assertSame('CONFLICT', DB::table('inbox_event')->where('id', $c->event_id)->value('result'));
            $this->assertSame(0, DB::table('audit_log')->where('action', 'sync.event.applied')->count());
        });
        $this->assertSame(['CONFLICT'], $this->outboxStatuses('cloud'), 'Cloud is told the event was evaluated (not lost) and shows CONFLICT');

        // Redelivery of the conflicting event does not re-apply or duplicate the conflict.
        $this->onNode('cloud', fn () => DB::table('outbox_event')->update(['sync_status' => 'QUEUED']));
        $this->onNode('local', fn () => app(Puller::class)->run());
        $this->onNode('local', fn () => $this->assertSame(1, DB::table('sync_conflict')->count()));
    }
}
