<?php

namespace App\Domain\Booking;

use App\Domain\Booking\Console\ExpireHoldsCommand;
use App\Domain\Booking\Contracts\BookingPaymentGateway;
use App\Domain\Booking\Contracts\CloudBookingAuthority;
use App\Domain\Booking\Contracts\ConnectivityProbe;
use App\Domain\Booking\Console\DemoSeedCommand;
use App\Domain\Booking\Services\BookingRules;
use App\Domain\Booking\Services\ConfigConnectivityProbe;
use App\Domain\Booking\Services\NullCloudBookingAuthority;
use App\Domain\Booking\Services\UnboundPaymentGateway;
use App\Domain\Booking\Services\UnlinkedPaymentGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Booking module (docs/MODULES.md). Seams other modules bind (all via `bind`/`bindIf`, defaults are safe no-ops):
 *   ConnectivityProbe, CloudBookingAuthority  -> Sync module (heartbeat + Local->Cloud call)
 *   BookingPaymentGateway                     -> Payments/Orders (Reception flow)
 */
class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BookingRules::class);
        $this->app->bindIf(ConnectivityProbe::class, ConfigConnectivityProbe::class);
        $this->app->bindIf(CloudBookingAuthority::class, NullCloudBookingAuthority::class);
        $this->app->bindIf(BookingPaymentGateway::class, fn () => config('booking.payment_gateway') === 'unlinked'
            ? new UnlinkedPaymentGateway
            : new UnboundPaymentGateway);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireHoldsCommand::class, DemoSeedCommand::class]);
        }
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('booking:expire-holds')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
