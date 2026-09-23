<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\ApprovalPort;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Domain\Payments\Contracts\PaymentProviderAdapter;
use App\Domain\Payments\Provider\PaystackAdapter;
use App\Domain\Payments\Services\DbApprovalPort;
use App\Domain\Payments\Services\DbOrderPort;
use App\Domain\Payments\Services\NullPayableSubjectResolver;
use App\Domain\Payments\Support\FacilityRules;
use Illuminate\Support\ServiceProvider;

/**
 * Payments module (ADR-0009 / architecture/07). Auto-registered by App\Providers\ModuleServiceProvider (docs/MODULES.md).
 * Other modules integrate through the ports bound here: rebind OrderPort / ApprovalPort / PayableSubjectResolver in their own
 * provider to replace the defaults, and listen for {@see Events\PaymentCaptured}.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderPort::class, DbOrderPort::class);
        $this->app->bind(ApprovalPort::class, DbApprovalPort::class);
        $this->app->bind(PayableSubjectResolver::class, NullPayableSubjectResolver::class);
        $this->app->bind(PaymentProviderAdapter::class, PaystackAdapter::class);
        $this->app->scoped(FacilityRules::class);
    }

    public function boot(): void
    {
        //
    }
}
