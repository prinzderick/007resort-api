<?php

namespace App\Domain\Sync\Jobs;

use App\Domain\Sync\Services\SiteAvailability;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * stale-site marker (queue job, Redis). The scheduler re-dispatches it on an interval; state lives in MySQL, so a lost job or a
 * stopped worker only delays sync. Unique for a short window so a stopped worker cannot pile up thousands of jobs.
 */
class MarkStaleSitesJob implements ShouldBeUnique, ShouldQueue
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
        return 'MarkStaleSitesJob';
    }

    public function handle(SiteAvailability $availability): void
    {
        $availability->markStale();
    }
}
