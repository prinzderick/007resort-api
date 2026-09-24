<?php

namespace App\Domain\Devices;

use App\Domain\Devices\Http\Middleware\AuthenticateAny;
use App\Domain\Devices\Http\Middleware\AuthenticateDevice;
use App\Domain\Devices\Http\Middleware\RequireStaffAndDevice;
use App\Domain\Devices\Services\CheckoutService;
use App\Domain\Devices\Services\DeviceCommandService;
use App\Domain\Devices\Services\DeviceService;
use App\Domain\Devices\Services\DeviceTokenService;
use App\Domain\Devices\Services\RealtimeAccess;
use App\Support\Realtime\Channels;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Devices: registration (one-time code -> device token), device authentication (`device`, `staff.device`, `auth.any` middleware),
 * tablet checkout/checkin, status heartbeat, commands, revoke, and the realtime channel rules of api/realtime.md.
 */
class DevicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([DeviceTokenService::class, DeviceService::class, DeviceCommandService::class, CheckoutService::class, RealtimeAccess::class] as $svc) {
            $this->app->singleton($svc);
        }
    }

    public function boot(): void
    {
        // Middleware aliases owned by this module (no shared file edited).
        $router = $this->app['router'];
        $router->aliasMiddleware('device', AuthenticateDevice::class);            // device | device:optional
        $router->aliasMiddleware('staff.device', RequireStaffAndDevice::class);   // staff bearer AND registered device
        $router->aliasMiddleware('auth.any', AuthenticateAny::class);             // staff bearer OR device token

        RateLimiter::for('device-register', fn (Request $r) => Limit::perMinute(10)->by('device-register:'.$r->ip()));

        $access = fn () => $this->app->make(RealtimeAccess::class);
        Channels::define('device.{deviceId}', fn (string $id) => $access()->ownDevice($id));
        Channels::define('site.status', fn () => $access()->siteStatus());
        Channels::define('kds.station.{stationId}', fn (string $id) => $access()->kdsStation($id));
        Channels::define('facility.{facilityId}.orders', fn (string $id) => $access()->facilityOrders($id));
    }
}
