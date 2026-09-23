<?php

namespace App\Domain\Booking\Console;

use App\Domain\Booking\Services\BookingService;
use Illuminate\Console\Command;

class ExpireHoldsCommand extends Command
{
    protected $signature = 'booking:expire-holds {--limit=500}';

    protected $description = 'Expire unpaid booking holds past their TTL and free their slots';

    public function handle(BookingService $bookings): int
    {
        $n = $bookings->expireDue((int) $this->option('limit'));
        $this->info("Expired {$n} hold(s).");

        return self::SUCCESS;
    }
}
