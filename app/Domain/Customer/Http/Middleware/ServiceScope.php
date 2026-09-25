<?php

namespace App\Domain\Customer\Http\Middleware;

use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `service.scope:<scope>` after `auth:service`: the website token must carry the scope (403 `scope_denied` otherwise). */
class ServiceScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (RequestContext::serviceTokenId() === null) {
            throw ApiProblem::unauthenticated();
        }
        if (! in_array($scope, explode(',', (string) RequestContext::get(RequestContext::SERVICE_SCOPE)), true)) {
            throw ApiProblem::forbidden('scope_denied', "The service token lacks the {$scope} scope.");
        }

        return $next($request);
    }
}
