<?php

namespace App\Domain\Audit;

use Illuminate\Support\ServiceProvider;

/**
 * Audit module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 * Optional siblings, all auto-loaded: routes.php (under /api/v1), Migrations/, config.php.
 */
class AuditServiceProvider extends ServiceProvider
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
