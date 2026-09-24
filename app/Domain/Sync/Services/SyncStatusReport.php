<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\Ts;
use App\Support\Ids;
use App\Support\Node;
use Illuminate\Support\Facades\DB;

/** Everything IT/Admin needs to see about sync health without database access (heartbeat-and-node-health §4). */
final class SyncStatusReport
{
    /** @return array<string, mixed> contract SyncStatus (+ extra observability members) */
    public function build(): array
    {
        $outbox = fn (array $st) => DB::table('outbox_event')->whereIn('sync_status', $st);
        $pending = $outbox(['LOCAL', 'QUEUED', 'SYNCING']);
        $failed = $outbox(['FAILED'])->count();
        $conflict = $outbox(['CONFLICT'])->count();
        $openConflicts = DB::table('sync_conflict')->where('status', 'OPEN')->count();
        $deferred = DB::table('inbox_event')->where('result', 'PENDING')->count();
        $inboxFailed = DB::table('inbox_event')->where('result', 'FAILED')->count();

        $sites = [];
        $peerHeartbeat = null;
        $reachable = false;
        if (Node::isCloud()) {
            $availability = app(SiteAvailability::class);
            foreach (DB::table('site_health')->orderBy('site_id')->get() as $r) {
                $s = $availability->snapshot(Ids::fromBinary($r->site_id));
                $sites[] = $s + ['outboxDepth' => $r->queue_depth === null ? null : (int) $r->queue_depth, 'oldestUnsyncedAt' => Ts::iso($r->oldest_unsynced_at), 'services' => $r->services ? json_decode($r->services, true) : null];
                $peerHeartbeat = max($peerHeartbeat ?? '', (string) $r->last_heartbeat_at) ?: null;
            }
            $reachable = $peerHeartbeat !== null && Ts::parse($peerHeartbeat)->greaterThanOrEqualTo(Ts::now()->subSeconds((int) config('sync.stale_after')));
        } else {
            $reachable = SyncState::peerReachable();
        }

        $degraded = ! $reachable || $failed > 0 || $openConflicts > 0 || $inboxFailed > 0;

        return [
            'node' => Node::name(),
            'siteId' => Node::siteId(),
            'peerReachable' => $reachable,
            'lastHeartbeatAt' => Ts::iso(SyncState::get(SyncState::LAST_HEARTBEAT_OK)),
            'lastPeerHeartbeatAt' => Ts::iso($peerHeartbeat),
            'outbox' => [
                'queued' => (clone $pending)->count(),
                'failed' => $failed,
                'conflict' => $conflict,
                'oldestQueuedAt' => Ts::iso((clone $pending)->min('created_at')),
            ],
            'inbox' => ['conflict' => DB::table('inbox_event')->where('result', 'CONFLICT')->count(), 'deferred' => $deferred, 'failed' => $inboxFailed],
            'health' => $degraded ? 'DEGRADED' : 'ONLINE',
            'openConflicts' => $openConflicts,
            'lastPushAt' => Ts::iso(SyncState::get(SyncState::LAST_PUSH_OK)),
            'lastPullAt' => Ts::iso(SyncState::get(SyncState::LAST_PULL_OK)),
            'lastError' => SyncState::get(SyncState::LAST_ERROR),
            'lastErrorAt' => Ts::iso(SyncState::get(SyncState::LAST_ERROR_AT)),
            'staleAfterSeconds' => (int) config('sync.stale_after'),
            'sites' => $sites,
        ];
    }
}
