<?php

namespace App\Domain\Customer;

use App\Domain\Booking\Services\BookingPayableSubjectResolver;
use App\Domain\Customer\Console\ServiceTokenCommand;
use App\Domain\Customer\Http\Middleware\PermissionOrPublic;
use App\Domain\Customer\Models\CustomerSession;
use App\Domain\Customer\Services\CompositePayableSubjectResolver;
use App\Domain\Customer\Services\CustomerAuthService;
use App\Domain\Customer\Services\ServiceTokenService;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Support\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerAuthService::class);
        $this->app->singleton(ServiceTokenService::class);
        // Guards are declared here (not in config/auth.php) so the module stays self-contained.
        $this->app['config']->set('auth.guards.customer', ['driver' => 'customer-token', 'provider' => null]);
        $this->app['config']->set('auth.guards.service', ['driver' => 'service-token', 'provider' => null]);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('permission.public', PermissionOrPublic::class);

        Auth::viaRequest('customer-token', function (Request $request) {
            $token = $request->bearerToken();
            if ($token === null || ! str_starts_with($token, CustomerAuthService::ACCESS_PREFIX)) {
                return null;
            }
            $session = CustomerSession::query()->with('account.customer')->where('access_token_hash', hash('sha256', $token))->first();
            $now = now('UTC');
            if ($session === null || $session->revoked_at !== null || $session->expires_at->lte($now)) {
                return null;
            }
            if ($session->access_expires_at->lte($now)) {
                RequestContext::set(RequestContext::TOKEN_EXPIRED, '1');

                return null;
            }
            $account = $session->account;
            $customer = $account?->customer;
            if ($account === null || ! $account->is_active || $account->email_verified_at === null || $customer === null || ! $customer->is_active || $customer->deleted_at !== null) {
                return null;
            }
            RequestContext::set(RequestContext::CUSTOMER_ID, $customer->id);
            RequestContext::set(RequestContext::CUSTOMER_ACCOUNT_ID, $account->id);
            RequestContext::set(RequestContext::ORGANIZATION_ID, $customer->organization_id);

            return $account;
        });

        Auth::viaRequest('service-token', function (Request $request) {
            $token = $request->bearerToken();
            if ($token === null || ! str_starts_with($token, ServiceTokenService::PREFIX)) {
                return null;
            }
            $row = app(ServiceTokenService::class)->authenticate($token);
            if ($row === null) {
                return null;
            }
            RequestContext::set(RequestContext::SERVICE_TOKEN_ID, $row->id);
            RequestContext::set(RequestContext::ORGANIZATION_ID, $row->organization_id);

            return $row;
        });

        $ipEmail = fn (Request $r) => strtolower((string) $r->input('email')).'|'.$r->ip();
        RateLimiter::for('customer-register', fn (Request $r) => [Limit::perHour(10)->by('reg-ip:'.$r->ip()), Limit::perHour(5)->by('reg:'.$ipEmail($r))]);
        RateLimiter::for('customer-login', fn (Request $r) => [Limit::perMinute(10)->by('cl:'.$ipEmail($r)), Limit::perMinute(60)->by('cl-ip:'.$r->ip())]);
        RateLimiter::for('customer-verify', fn (Request $r) => [Limit::perMinute(10)->by('cv:'.$ipEmail($r)), Limit::perMinute(60)->by('cv-ip:'.$r->ip())]);
        RateLimiter::for('customer-forgot', fn (Request $r) => [Limit::perMinutes(15, 5)->by('cf:'.$ipEmail($r)), Limit::perHour(30)->by('cf-ip:'.$r->ip())]);
        RateLimiter::for('customer-refresh', fn (Request $r) => Limit::perMinute(30)->by('cr:'.$r->ip()));
        RateLimiter::for('customer-api', fn (Request $r) => Limit::perMinute(240)->by('capi:'.(RequestContext::customerId() ?? RequestContext::serviceTokenId() ?? $r->ip())));
        RateLimiter::for('public-site', fn (Request $r) => Limit::perMinute(120)->by('psite:'.$r->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ServiceTokenCommand::class]);
        }

        // Online payments for memberships as well as bookings: wins over Booking's resolver (booted callbacks run in provider order).
        $this->app->booted(function (): void {
            if (interface_exists(PayableSubjectResolver::class)) {
                $this->app->bind(PayableSubjectResolver::class, fn ($app) => new CompositePayableSubjectResolver($app->make(BookingPayableSubjectResolver::class)));
            }
        });
    }
}
