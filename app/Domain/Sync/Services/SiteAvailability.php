<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Events\SiteHealthChanged;
use App\Domain\Sync\Support\Ts;
use App\Support\Ids;
use App\Support\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Is the property's Local node currently reachable?" — the gate for IMMEDIATE-fulfilment online orders
 * (architecture/sync/booking-authority-and-offline-allocation.md §4, ADR-0013 §4).
 *
 *   if (! app(SiteAvailability::class)->isLocalFresh()) { throw ApiProblem::conflict('site_offline', ...); }
 *
 * Freshness is computed from `last_heartbeat_at` against `sync.stale_after` at call time — it never depends on the
 * scheduled OFFLINE marker having run, so the gate is correct even if the scheduler lags. On the Local node itself
 * the answer is always true (we ARE the local node). Only the gate for immediate orders uses this; the rest of the
 * website must not.
 */
class SiteAvailability
{
    public function isLocalFresh(?string $siteId = null): bool
    {
        if (Node::isLocal()) {
            return true;
        }
        $last = $this->lastHeartbeatAt($siteId);

        return $last !== null && $last->greaterThanOrEqualTo(Ts::now()->subSeconds((int) config('sync.stale_after')));
    }

    public function lastHeartbeatAt(?string $siteId = null): ?CarbonImmutable
    {
        $row = $this->row($siteId);

        return $row?->last_heartbeat_at === null ? null : Ts::parse($row->last_heartbeat_at);
    }

    /** @return array{siteId: ?string, status: string, fresh: bool, lastHeartbeatAt: ?string, lastSyncAt: ?string, ageSeconds: ?int, appVersion: ?string, queueDepth: ?int} */
    public function snapshot(?string $siteId = null): array
    {
        $row = $this->row($siteId);
        $last = $row?->last_heartbeat_at === null ? null : Ts::parse($row->last_heartbeat_at);

        return [
            'siteId' => $row ? Ids::fromBinary($row->site_id) : ($siteId ?? Node::siteId()),
            'status' => $row->status ?? 'UNKNOWN',
            'fresh' => $this->isLocalFresh($siteId),
            'lastHeartbeatAt' => Ts::iso($row->last_heartbeat_at ?? null),
            'lastSyncAt' => Ts::iso($row->last_sync_at ?? null),
            'ageSeconds' => $last === null ? null : max(0, (int) $last->diffInSeconds(Ts::now(), true)),
            'appVersion' => $row->app_version ?? null,
            'queueDepth' => $row?->queue_depth === null ? null : (int) $row->queue_depth,
        ];
    }

    /**
     * Scheduled marker: ONLINE sites silent for longer than `stale_after` (i.e. several missed heartbeats, not one
     * dropped packet) become OFFLINE and a `site.health` event is broadcast.
     *
     * @return int sites flipped
     */
    public function markStale(): int
    {
        $cut = Ts::db(Ts::now()->subSeconds((int) config('sync.stale_after')));
        $stale = DB::table('site_health')->where('status', 'ONLINE')->where('last_heartbeat_at', '<', $cut)->get();
        $n = 0;
        foreach ($stale as $row) {
            $changed = DB::table('site_health')->where('site_id', $row->site_id)->where('status', 'ONLINE')->where('last_heartbeat_at', '<', $cut)
                ->update(['status' => 'OFFLINE', 'updated_at' => Ts::db()]);
            if ($changed > 0) {
                $n++;
                SiteHealthChanged::announce([
                    'siteId' => Ids::fromBinary($row->site_id), 'status' => 'OFFLINE',
                    'checks' => ['cloudLink' => 'down'], 'outboxDepth' => (int) ($row->queue_depth ?? 0),
                    'lastHeartbeatAt' => Ts::iso($row->last_heartbeat_at),
                ]);
            }
        }

        return $n;
    }

    private function row(?string $siteId): ?object
    {
        $siteId ??= Node::siteId();
        $q = DB::table('site_health');
        if ($siteId !== null) {
            $q->where('site_id', Ids::toBinary($siteId));
        }

        return $q->orderByDesc('last_heartbeat_at')->first();
    }
}
