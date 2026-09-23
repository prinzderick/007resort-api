<?php

namespace App\Domain\Sync\Jobs;

use App\Domain\Sync\Services\InboxProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * deferred inbox reprocessor (queue job, Redis). The scheduler re-dispatches it on an interval; state lives in MySQL, so a lost job or a
 * stopped worker only delays sync. Unique for a short window so a stopped worker cannot pile up thousands of jobs.
 */
class ReprocessInboxJob implements ShouldBeUnique, ShouldQueue
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
        return 'ReprocessInboxJob';
    }

    public function handle(InboxProcessor $inbox): void
    {
        $inbox->reprocessDue();
    }
}
