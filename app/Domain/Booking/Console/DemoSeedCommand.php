<?php

namespace App\Domain\Booking\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * `php artisan r007:demo-seed` — runs every module's `Seeders/*DemoSeeder.php` (idempotent, find-or-create), in module
 * name order. Refuses in production. Demo credentials are throwaway dev values (printed by the seeders).
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'r007:demo-seed {--only= : run only seeders whose class name contains this text} {--force : allow in production (NEVER on a live site)}';

    protected $description = 'Seed idempotent demo data for the mobile/POS demo (dev/staging only)';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed demo data in production.');

            return self::FAILURE;
        }
        $only = (string) $this->option('only');
        foreach (File::glob(app_path('Domain/*/Seeders/*DemoSeeder.php')) as $file) {
            $module = basename(dirname(dirname($file)));
            $class = 'App\\Domain\\'.$module.'\\Seeders\\'.basename($file, '.php');
            if (! class_exists($class) || ($only !== '' && ! str_contains($class, $only))) {
                continue;
            }
            /** @var Seeder $seeder */
            $seeder = app($class);
            $seeder->setContainer(app())->setCommand($this);
            $this->line("<info>Seeding</info> {$class}");
            $seeder->__invoke();
        }

        return self::SUCCESS;
    }
}
