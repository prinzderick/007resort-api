<?php

namespace App\Domain\Payments;

use App\Domain\Orders\Approvals\ApprovalService;
use App\Domain\Payments\Console\ExpirePendingCollectionsCommand;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Domain\Payments\Contracts\PaymentProviderAdapter;
use App\Domain\Payments\Events\PaymentCaptured;
use App\Domain\Payments\Listeners\CollectionCaptureListener;
use App\Domain\Payments\Provider\PaystackAdapter;
use App\Domain\Payments\Services\DbOrderPort;
use App\Domain\Payments\Services\NullPayableSubjectResolver;
use App\Domain\Payments\Services\PaymentApprovalHandler;
use App\Domain\Payments\Services\RefundService;
use App\Domain\Payments\Services\TerminalAdapterRegistry;
use App\Domain\Payments\Support\FacilityRules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Payments module (ADR-0009 / architecture/07). Auto-registered by App\Providers\ModuleServiceProvider (docs/MODULES.md).
 * Other modules integrate through the ports bound here: rebind OrderPort / PayableSubjectResolver in their own
 * provider to replace the defaults, and listen for {@see PaymentCaptured}.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderPort::class, DbOrderPort::class);
        $this->app->bind(PayableSubjectResolver::class, NullPayableSubjectResolver::class);
        $this->app->bind(PaymentProviderAdapter::class, PaystackAdapter::class);
        $this->app->scoped(FacilityRules::class);
        $this->app->singleton(TerminalAdapterRegistry::class);
    }

    public function boot(): void
    {
        // Refund / reversal approvals are decided through Orders' `/approvals/{id}/decision`; approving applies them here.
        $handler = $this->app->make(PaymentApprovalHandler::class);
        $approvals = $this->app->make(ApprovalService::class);
        $approvals->registerHandler(RefundService::REFUND, $handler);
        $approvals->registerHandler(RefundService::REVERSAL, $handler);

        // Waiter collection: provider-confirmed captures (Paystack) record their decision + tell the waiter's device.
        Event::listen(PaymentCaptured::class, [CollectionCaptureListener::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpirePendingCollectionsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('r007:payments:expire-pending-collections')->everyMinute()->withoutOverlapping(5)->runInBackground();
        });
    }
}
