<?php

namespace App\Domain\Payments;

use Illuminate\Support\ServiceProvider;

/**
 * Payments module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 * Optional siblings, all auto-loaded: routes.php (under /api/v1), Migrations/, config.php.
 */
class PaymentsServiceProvider extends ServiceProvider
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
