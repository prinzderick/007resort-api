<?php

namespace App\Support\Demo;

/**
 * A module's DEV-ONLY demo data. Put a class implementing this at app/Domain/<Module>/Demo/<Something>Seeder.php and
 * `php artisan r007:demo-seed` discovers and runs it (ordered by priority(); lower first). Must be idempotent (re-runnable):
 * use DemoIds for deterministic ids and updateOrCreate. Core: Organization 10, Identity 20, Devices 30. Use 100+ for yours.
 */
interface DemoSeeder
{
    public function priority(): int;

    public function run(DemoContext $context): void;
}
