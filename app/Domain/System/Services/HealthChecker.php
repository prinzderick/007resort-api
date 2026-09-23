<?php

namespace App\Domain\System\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Node health: DB + Redis are hard dependencies (down => 503); a backed-up outbox degrades the cloud link. */
class HealthChecker
{
    /** @return array{status: string, checks: array<string, string>, outboxDepth: int, serverTime: string} */
    public function check(): array
    {
        $checks = ['database' => $this->probe(fn () => DB::select('select 1')), 'redis' => $this->probe(fn () => Redis::connection()->ping())];
        $checks['queue'] = $checks['redis']; // the queue runs on Redis
        $depth = 0;
        $checks['cloudLink'] = 'ok';
        if ($checks['database'] === 'ok') {
            try {
                $pending = DB::table('outbox_event')->whereIn('sync_status', ['LOCAL', 'QUEUED', 'SYNCING', 'FAILED']);
                $depth = (int) (clone $pending)->count();
                $oldest = (clone $pending)->min('created_at');
                if ($oldest !== null && CarbonImmutable::parse($oldest, 'UTC')->lt(now('UTC')->subMinutes(10))) {
                    $checks['cloudLink'] = 'degraded';
                }
            } catch (Throwable) {
                // sync tables not migrated yet: not a health problem
            }
        }
        $status = ($checks['database'] === 'down' || $checks['redis'] === 'down') ? 'down' : (in_array('degraded', $checks, true) ? 'degraded' : 'ok');

        return ['status' => $status, 'checks' => $checks, 'outboxDepth' => $depth, 'serverTime' => now('UTC')->format('Y-m-d\TH:i:s.v\Z')];
    }

    private function probe(callable $fn): string
    {
        try {
            $fn();

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }
}
