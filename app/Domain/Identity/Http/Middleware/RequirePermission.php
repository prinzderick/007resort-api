<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<code>[,facility=<key>|site=<key>|organization=<key>]`
 *
 *   ->middleware(['auth:staff', 'permission:order.void.approve'])                  // holds it anywhere
 *   ->middleware(['auth:staff', 'permission:order.settle,facility=facilityId'])   // covers that facility
 *
 * `<key>` is looked up as a route parameter, then in the request input/query. Permission-based ONLY:
 * role names are never consulted. 403 `permission_denied` (+ `permission` member) when not granted.
 */
class RequirePermission
{
    public function __construct(private readonly PermissionChecker $checker) {}

    public function handle(Request $request, Closure $next, string $permission, ?string $scopeSpec = null): Response
    {
        $staffId = RequestContext::staffId();
        if ($staffId === null) {
            if (RequestContext::customerId() !== null || RequestContext::serviceTokenId() !== null) {
                throw ApiProblem::permissionDenied(explode('|', $permission)[0]); // a customer / service credential holds no staff permission
            }
            throw ApiProblem::unauthenticated();
        }

        // `permission:a|b` = holds ANY of them (rarely needed; prefer one code).
        $alternatives = explode('|', $permission);
        $deny = fn () => ApiProblem::permissionDenied($alternatives[0]);
        $holds = fn (?Scope $scope = null) => (bool) array_filter($alternatives, fn ($p) => $this->checker->can($staffId, $p, $scope));

        if (! $holds()) {
            throw $deny();
        }

        if ($scopeSpec !== null && str_contains($scopeSpec, '=')) {
            [$kind, $key] = explode('=', $scopeSpec, 2);
            $value = $request->route($key);
            $value = is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value;
            $value ??= $request->input($key);
            if (! is_string($value) || $value === '') {
                throw ApiProblem::badRequest('validation_failed', "'{$key}' is required to authorize this request.");
            }
            $scope = match ($kind) {
                'facility' => Scope::facility($value),
                'site' => Scope::site($value),
                'organization' => Scope::organization($value),
                default => throw new \InvalidArgumentException("Unknown permission scope kind '{$kind}'."),
            };
            try {
                if (! $holds($scope)) {
                    throw $deny();
                }
            } catch (ModelNotFoundException) {
                throw ApiProblem::notFound('not_found', 'The referenced facility/site was not found.');
            }
        }

        return $next($request);
    }
}
