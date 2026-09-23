<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\Ts;
use Illuminate\Support\Facades\DB;

/** Tiny durable key/value node state (sync_state). MySQL, not Redis: it must survive a cache flush. */
final class SyncState
{
    public const LAST_PUSH_OK = 'last_push_ok_at';

    public const LAST_PULL_OK = 'last_pull_ok_at';

    public const LAST_HEARTBEAT_OK = 'last_heartbeat_ok_at';

    public const LAST_ERROR = 'last_error';

    public const LAST_ERROR_AT = 'last_error_at';

    public const PULL_CURSOR = 'last_pulled_cursor';

    public static function set(string $key, ?string $value): void
    {
        DB::statement('INSERT INTO sync_state (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)', [$key, $value, Ts::db()]);
    }

    public static function touch(string $key): void
    {
        self::set($key, Ts::db());
    }

    public static function get(string $key): ?string
    {
        $v = DB::table('sync_state')->where('k', $key)->value('v');

        return $v === null ? null : (string) $v;
    }

    public static function error(string $message): void
    {
        self::set(self::LAST_ERROR, mb_substr($message, 0, 900));
        self::touch(self::LAST_ERROR_AT);
    }

    /** Peer counts as reachable when any push/pull/heartbeat round trip succeeded within `stale_after`. */
    public static function peerReachable(): bool
    {
        $latest = null;
        foreach ([self::LAST_PUSH_OK, self::LAST_PULL_OK, self::LAST_HEARTBEAT_OK] as $k) {
            $v = self::get($k);
            if ($v !== null && ($latest === null || $v > $latest)) {
                $latest = $v;
            }
        }

        return $latest !== null && Ts::parse($latest)->greaterThanOrEqualTo(Ts::now()->subSeconds((int) config('sync.stale_after')));
    }
}
