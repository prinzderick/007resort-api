<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Models\UserAccount;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Identity\Services\StaffAuthService;
use App\Domain\Identity\Services\StepUpService;
use App\Support\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionChecker::class);
        $this->app->singleton(StaffAuthService::class);
        $this->app->singleton(StepUpService::class);
    }

    public function boot(): void
    {
        // Guard `staff` (config/auth.php): opaque bearer access token -> session -> account.
        Auth::viaRequest('staff-token', function (Request $request): ?UserAccount {
            $token = $request->bearerToken();
            if ($token === null || $token === '') {
                return null;
            }
            $session = app(StaffAuthService::class)->sessionForAccessToken($token);
            if ($session === null) {
                return null;
            }
            $account = $session->account;
            $staff = $account->staff;
            RequestContext::set(RequestContext::STAFF_ID, $staff->id);
            RequestContext::set(RequestContext::ACCOUNT_ID, $account->id);
            RequestContext::set(RequestContext::SESSION_ID, $session->id);
            RequestContext::set(RequestContext::ORGANIZATION_ID, $staff->organization_id);
            RequestContext::set(RequestContext::SITE_ID, $staff->site_id);

            return $account;
        });

        RateLimiter::for('staff-login', fn (Request $r) => [
            Limit::perMinute(10)->by('login:'.$r->ip().'|'.strtolower((string) ($r->input('identifier') ?? $r->input('username')))),
            Limit::perMinute(60)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('auth-refresh', fn (Request $r) => Limit::perMinute(30)->by('refresh:'.$r->ip()));
    }
}
