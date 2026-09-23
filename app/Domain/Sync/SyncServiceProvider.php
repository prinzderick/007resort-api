<?php

namespace App\Domain\Sync;

use Illuminate\Support\ServiceProvider;

/**
 * Sync module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 * Optional siblings, all auto-loaded: routes.php (under /api/v1), Migrations/, config.php.
 */
class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
