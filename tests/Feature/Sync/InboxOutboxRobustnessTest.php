<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Services\OutboxPublisher;
use App\Domain\Sync\Services\PeerResponse;
use App\Domain\Sync\Services\SyncAdmin;
use App\Domain\Sync\Services\SyncApplierRegistry;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\Backoff;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\InboxOutcome;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PoisonApplier;
use Tests\Support\ProbeApplier;
use Tests\TwoNodeTestCase;

class InboxOutboxRobustnessTest extends TwoNodeTestCase
{
    private function publish(): array
    {
        return $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
    }

    private function outbox(string $eventId): object
    {
        return $this->onNode('local', fn () => DB::table('outbox_event')->where('id', Ids::toBinary($eventId))->first());
    }

    private function probeCount(string $node = 'cloud'): int
    {
        return $this->onNode($node, fn () => DB::table('sync_probe')->count());
    }

    public function test_unknown_event_type_is_stored_and_marked_failed_never_dropped_and_heals_when_an_applier_ships(): void
    {
        $e = $this->envelope('FutureEvent', $entity = Ids::uuid7(), 1, ['x' => 1]);
        $o = $this->onNode('cloud', fn () => app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($e)));
        $this->assertSame(InboxOutcome::FAILED, $o->result);
        $this->assertStringContainsString('no applier registered', (string) $o->detail);
        $this->onNode('cloud', function () use ($e) {
            $row = DB::table('inbox_event')->where('id', Ids::toBinary($e['eventId']))->first();
            $this->assertSame('FAILED', $row->result);
            $this->assertSame(['x' => 1], json_decode($row->payload, true), 'the full envelope is kept for replay');
        });

        // The applier is deployed later; the operator replays the failures.
        $this->applierRegistry()->register('FutureEvent', new ProbeApplier);
        $r = $this->onNode('cloud', fn () => app(SyncAdmin::class)->replayFailedInbox());
        $this->assertSame(['reprocessed' => 1, 'applied' => 1], $r);
        $this->assertSame(1, $this->probeCount());
        $this->onNode('cloud', fn () => $this->assertSame('APPLIED', DB::table('inbox_event')->value('result')));
    }

    public function test_applier_failure_rolls_back_its_own_writes_but_keeps_the_inbox_row_and_other_events_in_the_batch(): void
    {
        $this->applierRegistry()->register('Poison', new PoisonApplier);
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $poison = $this->envelope('Poison', Ids::uuid7(), 1, []);
        $fineA = $this->envelope('Fine', Ids::uuid7(), 1, []);
        $fineB = $this->envelope('Fine', Ids::uuid7(), 1, []);

        $this->useNode('cloud');
        $r = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson('/api/v1/sync/inbox', ['events' => [$fineA, $poison, $fineB]]);
        $r->assertOk();
        $this->assertSame(['APPLIED', 'FAILED', 'APPLIED'], array_column($r->json('results'), 'result'));
        $this->assertSame(2, DB::table('sync_probe')->count(), 'the poison applier partial write was rolled back to its savepoint');
        $this->assertSame(0, DB::table('sync_probe')->where('kind', 'POISON-PARTIAL-WRITE')->count());
        $row = DB::table('inbox_event')->where('id', Ids::toBinary($poison['eventId']))->first();
        $this->assertSame('FAILED', $row->result);
        $this->assertStringContainsString('boom', json_decode($row->last_error, true)['message']);
        $this->assertSame(3, DB::table('inbox_event')->count());
    }

    public function test_a_poison_event_does_not_block_the_queue_and_fails_terminally_after_max_attempts(): void
    {
        $this->override(['sync.max_attempts' => 3]);
        $this->applierRegistry()->register('Poison', new PoisonApplier);
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $poisonEntity = Ids::uuid7();
        $poison = $this->emit('local', 'Poison', 'Thing', $poisonEntity, []);
        $other1 = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $other2 = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);

        $r = $this->publish();
        $this->assertSame(2, $r['synced']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame('SYNCED', $this->outbox($other1)->sync_status, 'the queue is not blocked by the poison event');
        $this->assertSame('SYNCED', $this->outbox($other2)->sync_status);
        $this->assertSame('QUEUED', $this->outbox($poison)->sync_status);
        $this->assertSame(1, (int) $this->outbox($poison)->retry_count);

        // Retry with backoff until the poison is terminal.
        for ($i = 0; $i < 2; $i++) {
            $this->travel(301)->seconds();
            $this->publish();
        }
        $p = $this->outbox($poison);
        $this->assertSame('FAILED', $p->sync_status);
        $this->assertSame(3, (int) $p->retry_count);
        $this->assertStringContainsString('boom', json_decode($p->last_error, true)['message']);
        $this->assertSame(2, $this->probeCount(), 'only the healthy events were applied');

        // A LATER event of the poisoned entity waits behind it (order), while everything else keeps flowing.
        $behind = $this->emit('local', 'Fine', 'Thing', $poisonEntity, [], 2);
        $free = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $this->travel(301)->seconds();
        $this->publish();
        $this->assertSame('SYNCED', $this->outbox($free)->sync_status);
        $this->assertContains($this->outbox($behind)->sync_status, ['LOCAL', 'QUEUED'], 'held behind the FAILED event');

        // Operator ships a fixed applier and replays: the poisoned entity drains IN ORDER.
        $reg = new \ReflectionProperty(SyncApplierRegistry::class, 'appliers');
        $appliers = $reg->getValue($this->applierRegistry());
        $appliers['Poison'] = new ProbeApplier;
        $reg->setValue($this->applierRegistry(), $appliers);
        $this->assertSame(1, $this->onNode('local', fn () => app(SyncAdmin::class)->replayFailedOutbox()));
        $this->publish();
        $this->assertSame('SYNCED', $this->outbox($poison)->sync_status);
        $this->assertSame('SYNCED', $this->outbox($behind)->sync_status);
        $versions = $this->onNode('cloud', fn () => array_map('intval', DB::table('sync_probe')->where('entity_id', $poisonEntity)->orderBy('id')->pluck('version')->all()));
        $this->assertSame([1, 2], $versions);
    }

    public function test_peer_side_failures_never_terminally_fail_an_event(): void
    {
        $this->override(['sync.max_attempts' => 2]);
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $id = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);

        foreach ([500, 503, 401, 429, 404] as $status) {
            $this->peer->respond = fn () => new PeerResponse($status, ['code' => 'x'], $status === 429 ? 120 : null);
            $this->publish();
            $row = $this->outbox($id);
            $this->assertSame('QUEUED', $row->sync_status, "HTTP {$status} is the peer's problem, not the event's");
            $this->assertSame('UNREACHABLE', json_decode($row->last_error, true)['result']);
            $this->travel(3601)->seconds();
        }
        $this->assertSame(5, (int) $this->outbox($id)->retry_count);
        $this->peer->respond = null;
        $this->publish();
        $this->assertSame('SYNCED', $this->outbox($id)->sync_status);
    }

    public function test_a_batch_the_peer_rejects_as_malformed_is_split_so_one_bad_event_cannot_starve_the_rest(): void
    {
        $this->override(['sync.max_attempts' => 2]);
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $bad = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), ['bad' => true]);
        $good1 = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $good2 = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);

        // The peer answers 422 to any request that contains the bad event (whole-batch validation failure).
        $this->peer->respond = function ($m, $p, $json) {
            if ($p === '/sync/inbox' && collect($json['events'])->contains(fn ($e) => (((array) $e['payload'])['bad'] ?? false) === true)) {
                return new PeerResponse(422, ['code' => 'validation_failed']);
            }

            return null;
        };
        $r = $this->publish();
        $this->assertSame('SYNCED', $this->outbox($good1)->sync_status);
        $this->assertSame('SYNCED', $this->outbox($good2)->sync_status);
        $this->assertSame('QUEUED', $this->outbox($bad)->sync_status);
        $this->assertSame(1, (int) $this->outbox($bad)->retry_count);
        $this->assertFalse($r['peerDown']);
    }

    public function test_events_of_one_entity_are_never_sent_ahead_of_an_earlier_one_that_is_backing_off(): void
    {
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $e = Ids::uuid7();
        $first = $this->emit('local', 'Fine', 'Thing', $e, [], 1);
        $second = $this->emit('local', 'Fine', 'Thing', $e, [], 2);
        $other = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $this->onNode('local', fn () => DB::table('outbox_event')->where('id', Ids::toBinary($first))->update(['sync_status' => 'QUEUED', 'next_retry_at' => now('UTC')->addMinutes(5)->format('Y-m-d H:i:s.u')]));

        $this->publish();
        $this->assertSame('SYNCED', $this->outbox($other)->sync_status);
        $this->assertNotSame('SYNCED', $this->outbox($second)->sync_status, 'v2 must wait for v1');
        $this->assertSame(1, $this->probeCount());

        $this->travel(6)->minutes();
        $this->publish();
        $this->assertSame([1, 2], $this->onNode('cloud', fn () => array_map('intval', DB::table('sync_probe')->where('entity_id', $e)->orderBy('id')->pluck('version')->all())));
    }

    public function test_publishing_is_batched(): void
    {
        $this->override(['sync.batch_size' => 4]);
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        for ($i = 0; $i < 10; $i++) {
            $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        }
        $r = $this->publish();
        $this->assertSame(10, $r['synced']);
        $this->assertSame(3, $this->peer->callCount('/sync/inbox'), '10 events in batches of 4');
    }

    public function test_duplicate_registration_of_an_applier_is_a_programming_error(): void
    {
        $this->applierRegistry()->register('Once', new ProbeApplier);
        $this->expectException(\InvalidArgumentException::class);
        $this->applierRegistry()->register('Once', new ProbeApplier);
    }

    public function test_modules_can_register_class_string_and_closure_appliers(): void
    {
        $this->applierRegistry()->register('ViaClass', ProbeApplier::class);
        $this->applierRegistry()->register('ViaClosure', function (InboundEvent $e) {
            DB::table('sync_probe')->insert(['event_id' => $e->eventId, 'kind' => 'closure', 'entity_id' => $e->entityId, 'version' => 9]);

            return ApplyResult::applied();
        });
        foreach (['ViaClass', 'ViaClosure'] as $t) {
            $this->assertSame(InboxOutcome::APPLIED, $this->onNode('cloud', fn () => app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($this->envelope($t, Ids::uuid7(), 1, []))))->result);
        }
        $this->assertSame(2, $this->probeCount());
    }

    public function test_backoff_is_exponential_capped_and_jittered(): void
    {
        $max = fn (int $n) => Backoff::seconds($n, 5, 300, fn () => 0.999999);
        $min = fn (int $n) => Backoff::seconds($n, 5, 300, fn () => 0.0);
        $this->assertEqualsWithDelta(5.0, $max(0), 0.01);
        $this->assertEqualsWithDelta(10.0, $max(1), 0.01);
        $this->assertEqualsWithDelta(40.0, $max(3), 0.01);
        $this->assertEqualsWithDelta(300.0, $max(10), 0.01, 'capped');
        $this->assertEqualsWithDelta(300.0, $max(1000), 0.01, 'no overflow');
        $this->assertEqualsWithDelta(150.0, $min(10), 0.01, 'jitter spans [50%, 100%]');
        $samples = array_map(fn () => Backoff::seconds(6, 5, 300), range(1, 40));
        $this->assertGreaterThan(1, count(array_unique($samples)), 'real jitter varies');
        foreach ($samples as $s) {
            $this->assertGreaterThanOrEqual(150.0, $s);
            $this->assertLessThanOrEqual(300.0, $s);
        }
    }

    public function test_status_command_reports_the_backlog(): void
    {
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $this->peer->down = true;
        $this->publish();
        $this->useNode('local');
        Artisan::call('r007:sync:status', ['--json' => true]);
        $s = json_decode(Artisan::output(), true);
        $this->assertSame('local', $s['node']);
        $this->assertSame(1, $s['outbox']['queued']);
        $this->assertFalse($s['peerReachable']);
        $this->assertSame('DEGRADED', $s['health']);
        $this->assertStringContainsString('connection refused', (string) $s['lastError']);
    }
}
