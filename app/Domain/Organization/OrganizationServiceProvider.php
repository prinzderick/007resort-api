<?php

namespace App\Domain\Organization;

use App\Domain\Organization\Services\CapabilityService;
use App\Domain\Organization\Services\TaxSettingService;
use Illuminate\Support\ServiceProvider;

/** Organization: site, facility tree, capabilities + operating rules (ADR-0008), operating points, bookable resources, tax setting (ADR-0011). */
class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapabilityService::class);
        $this->app->singleton(TaxSettingService::class);
    }

    public function boot(): void
    {
        //
    }
}
