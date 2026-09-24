<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Services\NodeCredentials;
use App\Domain\Sync\Services\OutboxPublisher;
use App\Domain\Sync\Services\Puller;
use App\Support\Ids;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProbeApplier;
use Tests\TwoNodeTestCase;

class PullCommandsSchedulerTest extends TwoNodeTestCase
{
    public function test_pull_is_paginated_leased_and_acknowledged(): void
    {
        $this->override(['sync.pull_batch' => 2]);
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->emit('cloud', 'Fine', 'Thing', Ids::uuid7(), ['i' => $i]);
        }

        $r = $this->onNode('local', fn () => app(Puller::class)->run());
        $this->assertSame(5, $r['pulled']);
        $this->assertSame(5, $r['applied']);
        $this->assertSame(3, $this->peer->callCount('/sync/pull'), 'pages of 2, 2, 1');
        $this->assertSame(3, $this->peer->callCount('/sync/pull/ack'));
        $this->assertSame(array_fill(0, 5, 'SYNCED'), $this->onNode('cloud', fn () => DB::table('outbox_event')->orderBy('seq')->pluck('sync_status')->all()));

        // Nothing left to pull; a second run is a cheap no-op.
        $r = $this->onNode('local', fn () => app(Puller::class)->run());
        $this->assertSame(0, $r['pulled']);
    }

    public function test_pull_with_a_bound_credential_returns_only_that_sites_and_org_wide_events(): void
    {
        $this->override(['sync.node_token_hashes' => 'local@'.$this->site.':'.$this->tokenHash]);
        $mine = $this->emit('cloud', 'Fine', 'Thing', Ids::uuid7(), []);
        $this->emit('cloud', 'Fine', 'Thing', Ids::uuid7(), [], 1, Ids::uuid7());

        $resp = $this->onNode('local', fn () => $this->peer->request('GET', '/sync/pull', ['limit' => 10]));
        $this->assertSame(200, $resp->status);
        $this->assertCount(1, $resp->body['items']);
        $this->assertSame($mine, $resp->body['items'][0]['eventId']);
        $this->assertNull($resp->body['nextCursor']);

        // Leased: an immediate second pull does not hand the same un-acked event out again.
        $again = $this->onNode('local', fn () => $this->peer->request('GET', '/sync/pull', ['limit' => 10]));
        $this->assertCount(0, $again->body['items']);
        $this->travel(61)->seconds();
        $later = $this->onNode('local', fn () => $this->peer->request('GET', '/sync/pull', ['limit' => 10]));
        $this->assertCount(1, $later->body['items'], 'lease expired: served again (at-least-once)');

        $bad = $this->onNode('local', fn () => $this->peer->request('GET', '/sync/pull', ['cursor' => 'not-a-number']));
        $this->assertSame(400, $bad->status);
        $this->assertSame('invalid_cursor', $bad->problemCode());
    }

    public function test_only_one_publisher_drains_at_a_time(): void
    {
        $this->applierRegistry()->register('Fine', new ProbeApplier);
        $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $lock = 'r007:sync:publish:'.$this->onNode('local', fn () => DB::connection()->getDatabaseName());
        // Another worker (another connection) holds the publisher lock.
        $holder = DB::connection(self::CLOUD_CONNECTION);
        $this->assertSame(1, (int) $holder->selectOne('SELECT GET_LOCK(?, 0) AS l', [$lock])->l);
        $r = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertTrue($r['skipped']);
        $this->assertSame(0, $this->peer->callCount('/sync/inbox'));
        $holder->selectOne('SELECT RELEASE_LOCK(?) AS l', [$lock]);
        $r = $this->onNode('local', fn () => app(OutboxPublisher::class)->run());
        $this->assertFalse($r['skipped']);
        $this->assertSame(1, $r['synced']);
    }

    public function test_commands_status_retry_replay_failed_and_token(): void
    {
        $this->useNode('local');
        $failed = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        $other = $this->emit('local', 'Fine', 'Thing', Ids::uuid7(), []);
        DB::table('outbox_event')->whereIn('id', [Ids::toBinary($failed), Ids::toBinary($other)])->update(['sync_status' => 'FAILED', 'retry_count' => 8]);

        Artisan::call('r007:sync:status');
        $text = Artisan::output();
        $this->assertStringContainsString('Node local', $text);
        $this->assertStringContainsString('failed', $text);

        $this->assertSame(0, Artisan::call('r007:sync:retry', ['id' => $failed]));
        $this->assertSame('QUEUED', DB::table('outbox_event')->where('id', Ids::toBinary($failed))->value('sync_status'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'sync.outbox.retry')->count());
        $this->assertSame(1, Artisan::call('r007:sync:retry', ['id' => $failed]), 'not FAILED any more');
        $this->assertSame(2, Artisan::call('r007:sync:retry', ['id' => 'not-a-uuid']));

        $this->assertSame(0, Artisan::call('r007:sync:replay-failed'));
        $this->assertStringContainsString('1 FAILED event(s) re-queued', Artisan::output());
        $this->assertSame(0, DB::table('outbox_event')->where('sync_status', 'FAILED')->count());

        $this->assertSame(0, Artisan::call('r007:sync:token', ['peer' => 'local']));
        $out = Artisan::output();
        preg_match('/PEER_NODE_TOKEN[^:]*: (\S+)/', $out, $t);
        preg_match('/NODE_TOKEN_HASHES entry[^:]*: local:([0-9a-f]{64})/', $out, $h);
        $this->assertSame(hash('sha256', $t[1]), $h[1]);
        $this->assertNotNull(NodeCredentials::generate()['token']);
    }

    public function test_the_scheduler_registers_the_right_jobs_per_node_role(): void
    {
        $names = fn () => collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->filter(fn ($d) => str_starts_with((string) $d, 'sync:'))->values()->all();
        // The suite boots as APP_NODE=local. Other modules schedule their own jobs (e.g. site-health-keepalive): assert only the sync ones.
        $this->assertEqualsCanonicalizing(['sync:publish', 'sync:pull', 'sync:heartbeat', 'sync:reprocess-inbox'], $names());
    }
}
