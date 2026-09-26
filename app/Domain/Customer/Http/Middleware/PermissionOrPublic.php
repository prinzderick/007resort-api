<?php

namespace App\Domain\Customer\Http\Middleware;

use App\Domain\Customer\Support\Actor;
use App\Domain\Identity\Http\Middleware\RequirePermission;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission.public:<code>[,scope]` for routes that admit staff AND customers (and, for reads, the website service token).
 * Staff go through the normal permission check. A customer passes (the controller/service enforces OWNERSHIP). A service
 * token (scope public.read) passes for GET/HEAD only; with scope public.checkout also for writes (the controller then requires a `guest` object or an X-Order-Token). Anything else: 401.
 */
class PermissionOrPublic
{
    public function __construct(private readonly RequirePermission $permission) {}

    public function handle(Request $request, Closure $next, string $permission, ?string $scopeSpec = null): Response
    {
        if (Actor::isStaff()) {
            return $this->permission->handle($request, $next, $permission, $scopeSpec);
        }
        if (Actor::isCustomer()) {
            return $next($request);
        }
        if (Actor::isService()) {
            if (! in_array($request->method(), ['GET', 'HEAD'], true) && ! Actor::serviceCan('public.checkout')) {
                throw ApiProblem::forbidden('scope_denied', 'The service token is read-only (public.read); guest checkout needs public.checkout.');
            }
            if (in_array($request->method(), ['GET', 'HEAD'], true) && ! Actor::serviceCan('public.read') && ! Actor::serviceCan('public.checkout')) {
                throw ApiProblem::forbidden('scope_denied', 'The service token lacks the public.read scope.');
            }

            return $next($request);
        }
        throw ApiProblem::unauthenticated();
    }
}
