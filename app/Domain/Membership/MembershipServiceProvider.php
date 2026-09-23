<?php

namespace App\Domain\Membership;

use Illuminate\Support\ServiceProvider;

/**
 * Membership module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 * Optional siblings, all auto-loaded: routes.php (under /api/v1), Migrations/, config.php.
 */
class MembershipServiceProvider extends ServiceProvider
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
