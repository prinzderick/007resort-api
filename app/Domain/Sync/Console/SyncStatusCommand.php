<?php

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Services\SyncStatusReport;
use Illuminate\Console\Command;

class SyncStatusCommand extends Command
{
    protected $signature = 'r007:sync:status {--json : machine-readable output}';

    protected $description = 'Show sync health: peer reachability, outbox/inbox depth, conflicts, last errors';

    public function handle(SyncStatusReport $report): int
    {
        $s = $report->build();
        if ($this->option('json')) {
            $this->line(json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->line(sprintf('Node %s (site %s) - health %s, peer %s', $s['node'], $s['siteId'] ?? '-', $s['health'], $s['peerReachable'] ? 'reachable' : 'UNREACHABLE'));
        $this->table(['Outbox', 'count'], [
            ['queued (LOCAL/QUEUED/SYNCING)', $s['outbox']['queued']], ['failed', $s['outbox']['failed']], ['conflict', $s['outbox']['conflict']],
            ['oldest queued', $s['outbox']['oldestQueuedAt'] ?? '-'],
        ]);
        $this->table(['Inbox / conflicts', 'count'], [
            ['deferred (PENDING)', $s['inbox']['deferred']], ['failed', $s['inbox']['failed']], ['conflict', $s['inbox']['conflict']], ['open sync_conflicts', $s['openConflicts']],
        ]);
        $this->line('Last push: '.($s['lastPushAt'] ?? '-').'  last pull: '.($s['lastPullAt'] ?? '-').'  last heartbeat: '.($s['lastHeartbeatAt'] ?? '-'));
        if ($s['lastError']) {
            $this->warn('Last error ('.$s['lastErrorAt'].'): '.$s['lastError']);
        }
        foreach ($s['sites'] as $site) {
            $this->line(sprintf('Site %s: %s (last heartbeat %s, %ss ago, queue depth %s)', $site['siteId'], $site['status'], $site['lastHeartbeatAt'] ?? '-', $site['ageSeconds'] ?? '-', $site['queueDepth'] ?? '-'));
        }

        return self::SUCCESS;
    }
}
