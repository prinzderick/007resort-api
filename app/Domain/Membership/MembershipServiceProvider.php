<?php

namespace App\Domain\Membership;

use App\Domain\Membership\Console\RunLifecycleCommand;
use App\Domain\Membership\Listeners\ActivateMembershipOnPaymentCaptured;
use App\Domain\Membership\Services\CustomerResolver;
use App\Domain\Membership\Services\MembershipLifecycle;
use App\Domain\Membership\Services\MembershipScheduler;
use App\Domain\Membership\Services\MembershipService;
use App\Domain\Membership\Services\MembershipValidator;
use App\Domain\Membership\Services\PlanService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Membership module. Auto-registered by App\Providers\ModuleServiceProvider (see docs/MODULES.md).
 */
class MembershipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MembershipLifecycle::class);
        $this->app->singleton(CustomerResolver::class);
        $this->app->singleton(PlanService::class);
        $this->app->singleton(MembershipService::class);
        $this->app->singleton(MembershipValidator::class);
        $this->app->singleton(MembershipScheduler::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RunLifecycleCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Idempotent + row-locked per membership, so overlapping runs / both nodes are safe; withoutOverlapping just saves work.
            $schedule->command('r007:membership:lifecycle')->everyTenMinutes()->withoutOverlapping(15);
        });

        // Payments module publishes this when a payment is captured (class may not exist yet; string registration is fine).
        Event::listen('App\\Domain\\Payments\\Events\\PaymentCaptured', ActivateMembershipOnPaymentCaptured::class);
    }
}
