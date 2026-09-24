<?php

namespace App\Domain\Booking;

use App\Domain\Booking\Console\DemoSeedCommand;
use App\Domain\Booking\Console\ExpireHoldsCommand;
use App\Domain\Booking\Contracts\BookingPaymentGateway;
use App\Domain\Booking\Contracts\CloudBookingAuthority;
use App\Domain\Booking\Contracts\ConnectivityProbe;
use App\Domain\Booking\Listeners\ConfirmBookingsForPaidOrder;
use App\Domain\Booking\Services\BookingPayableSubjectResolver;
use App\Domain\Booking\Services\BookingRules;
use App\Domain\Booking\Services\ConfigConnectivityProbe;
use App\Domain\Booking\Services\NullCloudBookingAuthority;
use App\Domain\Booking\Services\PaymentsBookingGateway;
use App\Domain\Booking\Services\SyncConnectivityProbe;
use App\Domain\Booking\Services\UnboundPaymentGateway;
use App\Domain\Booking\Services\UnlinkedPaymentGateway;
use App\Domain\Booking\Sync\BookingEventApplier;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
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
        // 'auto' (default): the real Orders+Payments gateway when both modules exist, else dev-only 'unlinked' (never in production).
        $this->app->bindIf(BookingPaymentGateway::class, function () {
            $mode = config('booking.payment_gateway');
            $available = class_exists(PaymentService::class) && class_exists(OrderService::class);
            if ($mode === 'unlinked' || ($mode === 'auto' && ! $available && ! app()->isProduction())) {
                return new UnlinkedPaymentGateway;
            }

            return $available ? new PaymentsBookingGateway : new UnboundPaymentGateway;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireHoldsCommand::class]);
            // Standalone `r007:demo-seed`; the app-level command (Organization/Identity/Devices demo framework) supersedes it and discovers Booking\Demo\BookingDemoSeeder.
            if (! class_exists('App\\Console\\Commands\\DemoSeed')) {
                $this->commands([DemoSeedCommand::class]);
            }
        }
        // Payments/Orders integration (referenced by name: not every build has those modules).
        foreach (['App\\Domain\\Payments\\Events\\PaymentCaptured', 'App\\Domain\\Orders\\Events\\OrderSettled'] as $event) {
            Event::listen($event, [ConfirmBookingsForPaidOrder::class, 'handle']);
        }
        // Sync module integration (referenced by name; absent on branches without Sync): inbox appliers + heartbeat-backed connectivity.
        $this->callAfterResolving('App\\Domain\\Sync\\Services\\SyncApplierRegistry', function ($registry): void {
            $registry->register(BookingEventApplier::EVENT_TYPES, BookingEventApplier::class);
        });
        $this->app->booted(function (): void {
            if (class_exists('App\\Domain\\Sync\\Services\\SyncState')) {
                $this->app->bind(ConnectivityProbe::class, SyncConnectivityProbe::class);
            }
        });
        $this->app->booted(function (): void {
            if (interface_exists(PayableSubjectResolver::class)) {
                $this->app->bind(PayableSubjectResolver::class, BookingPayableSubjectResolver::class); // after every provider: wins over the Null default
            }
        });
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('booking:expire-holds')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
