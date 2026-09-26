<?php

namespace App\Domain\Guest\Console;

use App\Domain\Guest\Services\GuestErasure;
use Illuminate\Console\Command;

class EraseCommand extends Command
{
    protected $signature = 'r007:guest-erase {--email=} {--phone=} {--reference=}';

    protected $description = 'Anonymise guest contact data on request (NDPR erasure); financial records are kept.';

    public function handle(GuestErasure $erasure): int
    {
        $r = $erasure->erase(['email' => $this->option('email'), 'phone' => $this->option('phone'), 'reference' => $this->option('reference')]);
        $this->line(json_encode($r));

        return self::SUCCESS;
    }
}
