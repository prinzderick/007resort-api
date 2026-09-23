<?php

namespace App\Domain\Devices\Http\Middleware;

use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth.any` — staff bearer token OR a device token. Used by device self-service endpoints (status, commands) that a tablet
 * needs before any staff member has signed in. Sets whichever contexts apply.
 */
class AuthenticateAny
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            if (app('auth')->guard('staff')->user() === null) {
                throw ApiProblem::unauthenticated();
            }
        } elseif ($request->header('X-Device-Token') === null) {
            throw ApiProblem::unauthenticated();
        }
        if ($request->header('X-Device-Token') !== null || RequestContext::staffId() === null) {
            return app(AuthenticateDevice::class)->handle($request, $next);
        }

        return $next($request);
    }
}
