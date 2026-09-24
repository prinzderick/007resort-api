<?php

namespace App\Domain\Reporting;

use App\Domain\Reporting\Console\InstallViewsCommand;
use Illuminate\Support\ServiceProvider;

/** Reporting module (read-only). Auto-registered by App\Providers\ModuleServiceProvider. */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallViewsCommand::class]);
        }
    }
}
