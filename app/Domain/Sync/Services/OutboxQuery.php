<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\Ts;
use Illuminate\Support\Facades\DB;

/** The single definition of "which outbox events may be sent next" (per-entity ordering + poison isolation). */
final class OutboxQuery
{
    /**
     * Claim up to $limit deliverable events (oldest first) and mark them SYNCING, atomically.
     *
     * An event is deliverable when it is LOCAL/QUEUED and due, AND no EARLIER event of the same entity is FAILED
     * (poison: the entity waits for an operator, everything else keeps flowing), backing off, or (publisher) in
     * flight. Result: strict per-entity order, no head-of-line blocking across entities.
     *
     * @param  bool  $serving  true for the pull endpoint: also re-serves SYNCING events whose lease expired
     * @return list<object> rows (BINARY ids)
     */
    public static function claim(int $limit, bool $serving = false, ?string $siteBin = null, int $afterSeq = 0): array
    {
        $now = Ts::db();
        $lease = Ts::db(Ts::now()->subSeconds((int) config('sync.pull_lease')));
        $bindings = ['now' => $now, 'after' => $afterSeq];
        $syncing = $serving ? "OR (o.sync_status = 'SYNCING' AND o.last_attempt_at < :lease)" : '';
        if ($serving) {
            $bindings['lease'] = $lease;
        }
        $site = '';
        if ($siteBin !== null) {
            $site = 'AND (o.site_id IS NULL OR o.site_id = :site)';
            $bindings['site'] = $siteBin;
        }
        $blockSyncing = $serving ? '' : "OR p.sync_status = 'SYNCING'";
        $bindings['now2'] = $now;
        $bindings['limit'] = $limit;

        return DB::transaction(function () use ($bindings, $syncing, $site, $blockSyncing, $now) {
            $rows = DB::select("SELECT o.* FROM outbox_event o
                WHERE (o.sync_status IN ('LOCAL','QUEUED') {$syncing})
                  AND (o.next_retry_at IS NULL OR o.next_retry_at <= :now)
                  AND o.seq > :after {$site}
                  AND NOT EXISTS (
                      SELECT 1 FROM outbox_event p
                      WHERE p.entity_type = o.entity_type AND p.entity_id = o.entity_id AND p.seq < o.seq
                        AND (p.sync_status = 'FAILED' {$blockSyncing}
                             OR (p.sync_status IN ('LOCAL','QUEUED') AND p.next_retry_at > :now2))
                  )
                ORDER BY o.seq LIMIT :limit FOR UPDATE SKIP LOCKED", $bindings);
            if ($rows !== []) {
                $ids = array_map(fn ($r) => $r->id, $rows);
                DB::table('outbox_event')->whereIn('id', $ids)->update(['sync_status' => 'SYNCING', 'last_attempt_at' => $now]);
            }

            return $rows;
        });
    }
}
