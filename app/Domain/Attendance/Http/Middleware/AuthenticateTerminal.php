<?php

namespace App\Domain\Attendance\Http\Middleware;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\TerminalService;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `attendance.terminal`: authenticates a biometric terminal/bridge by `X-Device-Token` (SHA-256 looked up in
 * attendance_device.token_hash, constant-time by virtue of the unique-index lookup on a hash). No Bearer/staff session.
 */
class AuthenticateTerminal
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->header('X-Device-Token', ''));
        if ($token === '') {
            throw ApiProblem::unauthenticated('unauthenticated', 'X-Device-Token is required.');
        }
        $device = AttendanceDevice::query()->where('token_hash', TerminalService::hash($token))->first();
        if ($device === null) {
            throw ApiProblem::unauthenticated('device_not_registered', 'Unknown device token.');
        }
        if ($device->status !== 'ACTIVE') {
            throw ApiProblem::forbidden('device_revoked', 'This terminal has been disabled.');
        }
        RequestContext::set(RequestContext::DEVICE_ID, $device->device_id);
        RequestContext::set(RequestContext::ORGANIZATION_ID, $device->organization_id);
        RequestContext::set(RequestContext::SITE_ID, $device->site_id);
        $request->attributes->set('attendance_device', $device);

        return $next($request);
    }
}
