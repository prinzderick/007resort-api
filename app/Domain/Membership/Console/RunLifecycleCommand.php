<?php

namespace App\Domain\Membership\Console;

use App\Domain\Membership\Services\MembershipScheduler;
use Illuminate\Console\Command;

class RunLifecycleCommand extends Command
{
    protected $signature = 'r007:membership:lifecycle';

    protected $description = 'Advance membership statuses by time: pending-renewal, grace, expire (idempotent; safe on both nodes).';

    public function handle(MembershipScheduler $scheduler): int
    {
        $r = $scheduler->run();
        $this->info(sprintf('pendingRenewal=%d grace=%d expired=%d', $r['pendingRenewal'], $r['grace'], $r['expired']));

        return self::SUCCESS;
    }
}
