<?php

namespace App\Domain\Cms\Console;

use App\Domain\Cms\Demo\CmsContentSeeder;
use Illuminate\Console\Command;

class CmsSeedCommand extends Command
{
    protected $signature = 'r007:cms-seed {--refresh-media : Only bring an already seeded database in line with the current stock photos (import new photos, repoint references, remove replaced photos); text is never touched}';

    protected $description = 'Seed website CMS defaults and believable demo content (idempotent). Refuses to run in production.';

    public function handle(CmsContentSeeder $seeder): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed demo website content in production.');

            return self::FAILURE;
        }
        $say = fn (string $line) => $this->info($line);
        $this->option('refresh-media') ? $seeder->refreshMedia($say) : $seeder->run($say);

        return self::SUCCESS;
    }
}
