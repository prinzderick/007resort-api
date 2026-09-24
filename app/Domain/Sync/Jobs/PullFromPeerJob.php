<?php

namespace App\Domain\Sync\Jobs;

use App\Domain\Sync\Services\Puller;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cloud -> Local puller (queue job, Redis). The scheduler re-dispatches it on an interval; state lives in MySQL, so a lost job or a
 * stopped worker only delays sync. Unique for a short window so a stopped worker cannot pile up thousands of jobs.
 */
class PullFromPeerJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 30;

    public function __construct()
    {
        $this->onQueue((string) config('sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'PullFromPeerJob';
    }

    public function handle(Puller $puller): void
    {
        $puller->run();
    }
}
