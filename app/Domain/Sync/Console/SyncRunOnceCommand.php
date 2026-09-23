<?php

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Services\HeartbeatService;
use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Services\OutboxPublisher;
use App\Domain\Sync\Services\Puller;
use Illuminate\Console\Command;

class SyncRunOnceCommand extends Command
{
    protected $signature = 'r007:sync:run-once';

    protected $description = 'Run one synchronous sync cycle (heartbeat, push, pull, deferred inbox) — for debugging / two-node demos';

    public function handle(HeartbeatService $hb, OutboxPublisher $pub, Puller $pull, InboxProcessor $inbox): int
    {
        if (config('sync.heartbeat_enabled')) {
            $this->line('heartbeat: '.json_encode($hb->send()));
        }
        if (config('sync.push_enabled')) {
            $this->line('push: '.json_encode($pub->run()));
        }
        if (config('sync.pull_enabled')) {
            $this->line('pull: '.json_encode($pull->run()));
        }
        $this->line('deferred applied: '.$inbox->reprocessDue());

        return self::SUCCESS;
    }
}
