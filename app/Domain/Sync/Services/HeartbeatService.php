<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Events\SiteHealthChanged;
use App\Domain\Sync\Support\Ts;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Heartbeat both ways: Local sends (`send`), Cloud records (`receive`). architecture/sync/heartbeat-and-node-health.md */
final class HeartbeatService
{
    public function __construct(private readonly PeerClient $peer) {}

    /** Local -> Cloud. @return array{ok: bool, error?: string} */
    public function send(): array
    {
        $siteId = Node::siteId();
        if ($siteId === null) {
            SyncState::error('SITE_ID is not configured; cannot send heartbeats.');

            return ['ok' => false, 'error' => 'SITE_ID not configured'];
        }
        $pending = DB::table('outbox_event')->whereIn('sync_status', ['LOCAL', 'QUEUED', 'SYNCING']);
        $depth = (clone $pending)->count();
        $oldest = (clone $pending)->min('created_at');
        $lastSync = max(SyncState::get(SyncState::LAST_PUSH_OK) ?? '', SyncState::get(SyncState::LAST_PULL_OK) ?? '') ?: null;

        $body = [
            'nodeId' => $siteId,
            'sentAt' => Ts::iso(Ts::db()),
            'appVersion' => (string) config('node.version'),
            'outboxDepth' => $depth,
            'oldestUnsyncedAt' => Ts::iso($oldest),
            'lastPulledCursor' => SyncState::get(SyncState::PULL_CURSOR),
            'lastSyncAt' => Ts::iso($lastSync), // extension to the contract: feeds site_health.last_sync_at
            'services' => $this->services(),
        ];

        $link = 'ok';
        try {
            $resp = $this->peer->request('POST', '/sync/heartbeat', json: $body);
            if ($resp->ok()) {
                SyncState::touch(SyncState::LAST_HEARTBEAT_OK);
                $result = ['ok' => true];
            } else {
                $link = 'degraded';
                SyncState::error("peer answered HTTP {$resp->status} to heartbeat ".($resp->problemCode() ?? ''));
                $result = ['ok' => false, 'error' => 'HTTP '.$resp->status];
            }
        } catch (PeerUnreachable $e) {
            $link = 'down';
            SyncState::error($e->getMessage());
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        SiteHealthChanged::announce([
            'siteId' => $siteId, 'status' => $link === 'ok' ? 'ONLINE' : 'DEGRADED',
            'checks' => ['database' => 'ok', 'redis' => $body['services']['redis'], 'queue' => $body['services']['queue'], 'cloudLink' => $link],
            'outboxDepth' => $depth,
        ]);

        return $result;
    }

    /**
     * Cloud: record a heartbeat from Local. Idempotent (a replay only refreshes timestamps and is bounded by the
     * sentAt skew window).
     *
     * @param  array<string, mixed>  $b  validated request
     * @return array<string, mixed> contract SyncHeartbeatResponse
     */
    public function receive(array $b, ?string $boundSiteId): array
    {
        $siteId = Ids::normalize($b['nodeId']);
        if ($boundSiteId !== null && $boundSiteId !== $siteId) {
            throw ApiProblem::forbidden('node_site_mismatch', 'This node credential is not valid for that site.');
        }
        $sentAt = Ts::parse($b['sentAt']);
        $skew = abs(Ts::now()->diffInSeconds($sentAt, false));
        if ($skew > (int) config('sync.heartbeat_max_skew')) {
            throw ApiProblem::unprocessable('heartbeat_clock_skew', 'sentAt differs from server time by more than the allowed window.');
        }

        $now = Ts::db();
        $bin = Ids::toBinary($siteId);
        $previous = null;
        DB::transaction(function () use ($b, $bin, $now, &$previous) {
            $previous = DB::table('site_health')->where('site_id', $bin)->lockForUpdate()->value('status');
            $fields = [
                'status' => 'ONLINE', 'last_heartbeat_at' => $now,
                'app_version' => isset($b['appVersion']) ? mb_substr((string) $b['appVersion'], 0, 32) : null,
                'queue_depth' => isset($b['outboxDepth']) ? (int) $b['outboxDepth'] : null,
                'oldest_unsynced_at' => isset($b['oldestUnsyncedAt']) ? Ts::db(Ts::parse($b['oldestUnsyncedAt'])) : null,
                'last_pulled_cursor' => isset($b['lastPulledCursor']) ? mb_substr((string) $b['lastPulledCursor'], 0, 64) : null,
                'services' => isset($b['services']) ? json_encode($b['services']) : null,
                'updated_at' => $now,
            ];
            if (isset($b['lastSyncAt'])) {
                $fields['last_sync_at'] = Ts::db(Ts::parse($b['lastSyncAt']));
            }
            if ($previous === null) {
                DB::table('site_health')->insert($fields + ['site_id' => $bin]);
            } else {
                DB::table('site_health')->where('site_id', $bin)->update($fields);
            }
        }, 3);

        if ($previous !== 'ONLINE') {
            SiteHealthChanged::announce([
                'siteId' => $siteId, 'status' => 'ONLINE', 'checks' => ['cloudLink' => 'ok'],
                'outboxDepth' => (int) ($b['outboxDepth'] ?? 0), 'lastHeartbeatAt' => Ts::iso($now),
            ]);
        }

        return [
            'ackAt' => Ts::iso($now),
            'peerReachable' => true,
            'commandsPending' => (int) DB::table('outbox_event')->whereIn('sync_status', ['LOCAL', 'QUEUED'])
                ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $bin))->count(),
            'serverVersion' => (string) config('node.version'),
        ];
    }

    /** @return array<string, string> ok|degraded|down */
    private function services(): array
    {
        $redis = 'ok';
        try {
            Redis::connection()->ping();
        } catch (Throwable) {
            $redis = 'down';
        }

        return ['database' => 'ok', 'redis' => $redis, 'queue' => $redis === 'ok' ? 'ok' : 'degraded'];
    }
}
