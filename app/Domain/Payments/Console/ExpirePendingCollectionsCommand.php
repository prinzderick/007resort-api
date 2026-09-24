<?php

namespace App\Domain\Payments\Console;

use App\Domain\Payments\Services\CollectionService;
use Illuminate\Console\Command;

/** `php artisan r007:payments:expire-pending-collections` - scheduled every minute by PaymentsServiceProvider. */
final class ExpirePendingCollectionsCommand extends Command
{
    protected $signature = 'r007:payments:expire-pending-collections {--limit=200}';

    protected $description = 'Expire waiter collections nobody confirmed within the facility window (raises security events + realtime alerts)';

    public function handle(CollectionService $collections): int
    {
        $n = $collections->expireDue((int) $this->option('limit'));
        $this->info("Expired/released {$n} stale collection(s).");

        return self::SUCCESS;
    }
}
