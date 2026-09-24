<?php

namespace App\Domain\Cms\Console;

use App\Domain\Cms\Demo\CmsContentSeeder;
use Illuminate\Console\Command;

class CmsSeedCommand extends Command
{
    protected $signature = 'r007:cms-seed';

    protected $description = 'Seed website CMS defaults and believable demo content (idempotent). Refuses to run in production.';

    public function handle(CmsContentSeeder $seeder): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed demo website content in production.');

            return self::FAILURE;
        }
        $seeder->run(fn (string $line) => $this->info($line));

        return self::SUCCESS;
    }
}
