<?php

namespace App\Domain\Devices\Http\Middleware;

use App\Domain\Devices\Services\DeviceTokenService;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `device` / `device:optional` — validates `X-Device-Token` (registered, not revoked, not expired) and sets RequestContext::deviceId().
 * Missing/unknown token: 403 device_not_registered; revoked: 403 device_revoked. With `optional` an ABSENT header passes through
 * (an invalid one still fails).
 */
class AuthenticateDevice
{
    public function __construct(private readonly DeviceTokenService $tokens) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $token = $request->header('X-Device-Token');
        if ($token === null || $token === '') {
            if ($mode === 'optional') {
                return $next($request);
            }
            throw ApiProblem::forbidden('device_not_registered', 'A registered device is required (X-Device-Token).');
        }

        $resolved = $this->tokens->resolve($token) ?? throw ApiProblem::forbidden('device_not_registered', 'Unknown or expired device token.');
        if ($resolved['revoked']) {
            throw ApiProblem::forbidden('device_revoked', 'This device has been revoked.');
        }
        $device = $resolved['device'];
        RequestContext::set(RequestContext::DEVICE_ID, $device->id);
        if (RequestContext::siteId() === null) {
            RequestContext::set(RequestContext::SITE_ID, $device->site_id);
            RequestContext::set(RequestContext::ORGANIZATION_ID, $device->organization_id);
        }
        // Touch last_seen_at at most once a minute (cheap conditional update, no row churn).
        DB::table('device')->where('id', hex2bin(str_replace('-', '', $device->id)))
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now('UTC')->subMinute()->format('Y-m-d H:i:s.u')))
            ->update(['last_seen_at' => now('UTC')->format('Y-m-d H:i:s.u')]);

        return $next($request);
    }
}
