<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Services\SyncApplierRegistry;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\OrderedProbeApplier;
use Tests\Support\SyncWorkers;
use Tests\Support\TestData;

/** Real concurrency (separate PHP processes, separate MySQL connections) on the inbox. */
class InboxConcurrencyTest extends ConcurrentTestCase
{
    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE TABLE IF NOT EXISTS sync_probe (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event_id VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, entity_id VARCHAR(36) NOT NULL, version INT NOT NULL) ENGINE=InnoDB');
        DB::table('sync_probe')->delete();
        $this->t = TestData::tenant();
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS sync_probe');
        parent::tearDown();
    }

    private function envelope(string $type, string $entity, int $version, ?string $id = null): array
    {
        return ['eventId' => $id ?? Ids::uuid7(), 'eventType' => $type, 'entityType' => 'Thing', 'entityId' => $entity, 'entityVersion' => $version,
            'organizationId' => $this->t['org'], 'siteId' => $this->t['site'], 'facilityId' => null, 'sourceNode' => 'local',
            'occurredAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'payload' => ['n' => $version]];
    }

    public function test_the_same_event_delivered_by_six_processes_at_once_is_applied_exactly_once(): void
    {
        $e = $this->envelope('ConcurrentProbe', Ids::uuid7(), 1);
        $results = array_map(fn ($r) => $r['result'] ?? $r['error'], Concurrent::run(6, SyncWorkers::class, 'deliver', [$e]));

        $counts = array_count_values($results);
        $this->assertSame(1, $counts['APPLIED'] ?? 0, json_encode($results));
        $this->assertSame(5, $counts['DUPLICATE'] ?? 0, json_encode($results));
        $this->assertSame(1, DB::table('sync_probe')->count());
        $this->assertSame(1, DB::table('inbox_event')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'sync.event.applied')->count());
    }

    public function test_versions_of_one_entity_delivered_in_parallel_converge_to_ordered_exactly_once_state(): void
    {
        $entity = Ids::uuid7();
        $events = array_map(fn ($v) => $this->envelope('ConcurrentOrdered', $entity, $v), [1, 2, 3, 4, 5]);
        // One process per event, all released together, newest version first: arrival order is arbitrary.
        $results = Concurrent::run(5, SyncWorkers::class, 'deliverNth', [array_reverse($events)]);
        $this->assertNotContains(null, array_column($results, 'result'), json_encode($results));
        foreach (array_column($results, 'result') as $r) {
            $this->assertStringNotContainsString('ERROR', (string) $r);
        }

        // Whatever raced (DEFERRED rows) is picked up by the scheduler / cascade.
        app(SyncApplierRegistry::class)->register('ConcurrentOrdered', new OrderedProbeApplier);
        $this->travel(120)->seconds();
        app(InboxProcessor::class)->reprocessDue();
        app(InboxProcessor::class)->drainDeferred('Thing', $entity);

        $this->assertSame(5, DB::table('inbox_event')->where('result', 'APPLIED')->count(), json_encode(DB::table('inbox_event')->pluck('result')));
        $versions = DB::table('sync_probe')->orderBy('id')->pluck('version')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([1, 2, 3, 4, 5], $versions, 'applied in entity order, each exactly once');
        $this->assertSame(5, (int) DB::table('sync_entity_version')->value('applied_version'));
    }
}
