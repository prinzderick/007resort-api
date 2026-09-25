<?php

namespace App\Domain\Customer;

use App\Domain\Booking\Services\BookingPayableSubjectResolver;
use App\Domain\Customer\Console\ServiceTokenCommand;
use App\Domain\Customer\Http\Middleware\PermissionOrPublic;
use App\Domain\Customer\Http\Middleware\ServiceScope;
use App\Domain\Customer\Http\Middleware\SocialLinkAuth;
use App\Domain\Customer\Models\ServiceToken;
use App\Domain\Customer\Services\CompositePayableSubjectResolver;
use App\Domain\Customer\Services\CustomerAuthService;
use App\Domain\Customer\Services\ServiceTokenService;
use App\Domain\Customer\Services\SocialLoginService;
use App\Domain\Customer\Social\GoogleIdTokenVerifier;
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
        $this->app->singleton(GoogleIdTokenVerifier::class);
        $this->app->singleton(SocialLoginService::class);
        // Guards are declared here (not in config/auth.php) so the module stays self-contained.
        $this->app['config']->set('auth.guards.customer', ['driver' => 'customer-token', 'provider' => null]);
        $this->app['config']->set('auth.guards.service', ['driver' => 'service-token', 'provider' => null]);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('permission.public', PermissionOrPublic::class);
        Route::aliasMiddleware('service.scope', ServiceScope::class);
        Route::aliasMiddleware('social.link.auth', SocialLinkAuth::class);

        Auth::viaRequest('customer-token', fn (Request $request) => app(CustomerAuthService::class)->authenticateAccessToken($request->bearerToken()));

        Auth::viaRequest('service-token', function (Request $request) {
            $token = $request->bearerToken();
            if ($token === null || ! str_starts_with($token, ServiceTokenService::PREFIX)) {
                return null;
            }
            $row = app(ServiceTokenService::class)->authenticate($token);
            if ($row === null) {
                return null;
            }
            // Routes that declare `service_scope` (the social endpoints) check the scope themselves (403); every other route is a public read.
            $declared = $request->route()?->defaults['service_scope'] ?? null;
            if ($declared === null && ! $row->hasScope(ServiceToken::SCOPE_PUBLIC_READ)) {
                return null;
            }
            RequestContext::set(RequestContext::SERVICE_SCOPE, (string) $row->scope);
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
        // Social sign-in: the website calls from ONE server IP, so limits are per service token, per provider identity and per end-user IP (clientIp).
        RateLimiter::for('social-login', fn (Request $r) => array_filter([
            Limit::perMinute(120)->by('sl:'.(RequestContext::serviceTokenId() ?? $r->ip())),
            Limit::perMinute(20)->by('slu:'.sha1(strtolower((string) $r->input('provider')).'|'.($r->input('providerUserId') ?? $r->input('idToken')))),
            $r->input('clientIp') ? Limit::perMinute(30)->by('sli:'.$r->input('clientIp')) : null,
        ]));
        RateLimiter::for('social-public', fn (Request $r) => [Limit::perMinute(20)->by('sp-ip:'.$r->ip())]);
        RateLimiter::for('social-confirm', fn (Request $r) => [Limit::perMinute(10)->by('sc:'.sha1(strtolower((string) $r->input('email')).'|'.$r->ip())), Limit::perMinute(60)->by('sc-tok:'.(RequestContext::serviceTokenId() ?? $r->ip()))]);
        RateLimiter::for('customer-credential', fn (Request $r) => Limit::perMinute(5)->by('cc:'.(RequestContext::customerId() ?? $r->ip())));
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
