<?php

namespace App\Domain\Reporting\Support;

use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Data-freshness metadata attached to EVERY report response (architecture/sync/heartbeat-and-node-health.md §"never present
 * Local-originated figures as live when the sync is stale").
 *
 *   generatedAt  when this response was computed (UTC)
 *   sourceNode   'local' | 'cloud' - the node that computed it
 *   lastSyncAt   last successful sync of the site's data (Cloud: site_health.last_sync_at; Local: newest SYNCED outbox event), or null
 *   stale        true when the figures may be behind reality
 *   staleReason  human/machine-readable cause (null when fresh)
 *   ageSeconds   seconds since lastSyncAt (null when unknown)
 *
 * Local is authoritative for on-site operations, so on a Local node figures are live (stale=false) - lastSyncAt then only says
 * how far the Cloud copy lags. On a Cloud node stale = site OFFLINE, never synced, or lastSyncAt older than reporting.stale_after_seconds.
 */
final class Freshness
{
    /** @return array{generatedAt: string, sourceNode: string, lastSyncAt: ?string, stale: bool, staleReason: ?string, ageSeconds: ?int, staleAfterSeconds: int} */
    public static function forSite(?string $siteId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $node = (string) config('node.node', 'local');
        $threshold = (int) config('reporting.stale_after_seconds');

        $status = null;
        $last = null;
        if ($siteId !== null) {
            $row = DB::table('site_health')->where('site_id', Ids::toBinary($siteId))->first(['status', 'last_sync_at']);
            $status = $row->status ?? null;
            $last = $row?->last_sync_at ? CarbonImmutable::parse($row->last_sync_at, 'UTC') : null;
        }
        if ($last === null && $node === 'local') {
            $ts = DB::table('outbox_event')->where('sync_status', 'SYNCED')->max('last_attempt_at');
            $last = $ts ? CarbonImmutable::parse($ts, 'UTC') : null;
        }
        $age = $last === null ? null : max(0, $now->getTimestamp() - $last->getTimestamp());

        $stale = false;
        $reason = null;
        if ($node === 'cloud') {
            if ($status === 'OFFLINE') {
                [$stale, $reason] = [true, 'site_offline'];
            } elseif ($last === null) {
                [$stale, $reason] = [true, 'never_synced'];
            } elseif ($age > $threshold) {
                [$stale, $reason] = [true, 'sync_lagging'];
            }
        }

        return [
            'generatedAt' => $now->format('Y-m-d\TH:i:s.v\Z'), 'sourceNode' => $node,
            'lastSyncAt' => $last?->format('Y-m-d\TH:i:s.v\Z'), 'stale' => $stale, 'staleReason' => $reason,
            'ageSeconds' => $age, 'staleAfterSeconds' => $threshold,
        ];
    }

    public static function siteOfFacility(string $facilityId): ?string
    {
        $v = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->value('site_id');

        return $v === null ? null : Ids::fromBinary($v);
    }
}
