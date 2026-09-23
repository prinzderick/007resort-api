<?php

namespace App\Domain\Ticketing;

use Illuminate\Support\ServiceProvider;

/**
 * Ticketing module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 * Optional siblings, all auto-loaded: routes.php (under /api/v1), Migrations/, config.php.
 */
class TicketingServiceProvider extends ServiceProvider
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
