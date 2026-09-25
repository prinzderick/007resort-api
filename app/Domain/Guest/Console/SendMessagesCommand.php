<?php

namespace App\Domain\Guest\Console;

use App\Domain\Guest\Services\GuestMessenger;
use Illuminate\Console\Command;

class SendMessagesCommand extends Command
{
    protected $signature = 'r007:guest-messages:send {--limit=50}';

    protected $description = 'Deliver queued guest confirmation / ticket emails and SMS (docs/GUEST_CHECKOUT.md s5).';

    public function handle(GuestMessenger $messenger): int
    {
        $r = $messenger->deliverDue((int) $this->option('limit'));
        $this->line("sent: {$r['sent']} failed: {$r['failed']}");

        return self::SUCCESS;
    }
}
