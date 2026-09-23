<?php

namespace App\Domain\Devices\Http\Middleware;

use App\Domain\Identity\Models\AuthSession;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `staff.device` — combined staff + device requirement (architecture/06 §5, 17 §1): a valid staff bearer token (auth:staff) AND a valid
 * registered device (X-Device-Token). If the staff session was created on a device, it must be used from that same device.
 * Put AFTER `auth:staff` and `device`:   ->middleware(['auth:staff', 'device', 'staff.device'])
 * (or simply `->middleware('staff.device')` — it enforces both by itself when they haven't run yet).
 */
class RequireStaffAndDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (RequestContext::staffId() === null) {
            app('auth')->guard('staff')->user();
            if (RequestContext::staffId() === null) {
                throw ApiProblem::unauthenticated();
            }
        }
        if (RequestContext::deviceId() === null) {
            // validates X-Device-Token (throws device_not_registered / device_revoked) and sets the device context
            app(AuthenticateDevice::class)->handle($request, fn () => response()->noContent());
        }
        $bound = AuthSession::query()->whereKey(RequestContext::sessionId())->value('device_id');
        if ($bound !== null && $bound !== RequestContext::deviceId()) {
            throw ApiProblem::forbidden('device_not_registered', 'This session belongs to a different device.');
        }

        return $next($request);
    }
}
