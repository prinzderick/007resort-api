<?php

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Services\SyncAdmin;
use Illuminate\Console\Command;

class SyncReplayFailedCommand extends Command
{
    protected $signature = 'r007:sync:replay-failed';

    protected $description = 'Re-queue every FAILED outbox event and reprocess every FAILED inbox event (audited)';

    public function handle(SyncAdmin $admin): int
    {
        $out = $admin->replayFailedOutbox();
        $in = $admin->replayFailedInbox();
        $this->info("Outbox: {$out} FAILED event(s) re-queued. Inbox: {$in['reprocessed']} reprocessed, {$in['applied']} applied.");

        return self::SUCCESS;
    }
}
