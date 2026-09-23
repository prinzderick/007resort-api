<?php

namespace App\Console\Commands;

use App\Providers\ModuleServiceProvider;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Seeds a complete DEV demo property. Refuses to run in production. Idempotent. Extensible: any module can ship
 * app/Domain/<Module>/Demo/*Seeder.php implementing App\Support\Demo\DemoSeeder (see that interface).
 */
class DemoSeed extends Command
{
    protected $signature = 'r007:demo-seed {--fresh : run migrate:fresh first (local/testing only)}';

    protected $description = 'DEV ONLY: seed the demo organization, facilities, capabilities, devices and one staff user per role';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed demo data in production.');

            return self::FAILURE;
        }
        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);
        }

        $seeders = [];
        foreach (ModuleServiceProvider::modules() as $module) {
            foreach (glob(app_path("Domain/{$module}/Demo/*Seeder.php")) ?: [] as $file) {
                $class = "App\\Domain\\{$module}\\Demo\\".basename($file, '.php');
                if (is_subclass_of($class, DemoSeeder::class)) {
                    $seeders[] = app($class);
                }
            }
        }
        usort($seeders, fn (DemoSeeder $a, DemoSeeder $b) => $a->priority() <=> $b->priority());

        $ctx = new DemoContext($this);
        foreach ($seeders as $seeder) {
            $this->line('<fg=cyan>›</> '.class_basename($seeder));
            $seeder->run($ctx);
        }

        $this->newLine();
        $this->warn('DEV-ONLY data. Set SITE_ID='.DemoIds::site().' in .env to pin this node to the demo site.');
        foreach ($ctx->tables as $t) {
            $this->newLine();
            $this->line("<options=bold>{$t['title']}</>");
            $this->table($t['headers'], $t['rows']);
        }

        return self::SUCCESS;
    }
}
