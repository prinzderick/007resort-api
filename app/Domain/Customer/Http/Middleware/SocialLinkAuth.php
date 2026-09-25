<?php

namespace App\Domain\Customer\Http\Middleware;

use App\Domain\Customer\Models\ServiceToken;
use App\Domain\Customer\Services\CustomerAuthService;
use App\Domain\Customer\Services\ServiceTokenService;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /customer/me/social/link` accepts exactly two credentials shapes:
 *   (a) `Authorization: Bearer r7c_...` (the customer) - the controller then requires a server-verifiable Google idToken, claims are refused;
 *   (b) `Authorization: Bearer r7s_...` (website, scope customer.social) + `X-Customer-Token: r7c_...` (which customer) - claims allowed.
 * The request context ends up with the customer id (and, in (b), the service token id; the controller reads {@see self::VIA_SERVICE}).
 */
class SocialLinkAuth
{
    public const VIA_SERVICE = 'r007.social_link_via_service';

    public function __construct(private readonly CustomerAuthService $auth, private readonly ServiceTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        if (str_starts_with($bearer, CustomerAuthService::ACCESS_PREFIX)) {
            $this->auth->authenticateAccessToken($bearer) ?? throw ApiProblem::unauthenticated();
        } elseif (str_starts_with($bearer, ServiceTokenService::PREFIX)) {
            $row = $this->tokens->authenticate($bearer) ?? throw ApiProblem::unauthenticated();
            if (! $row->hasScope(ServiceToken::SCOPE_CUSTOMER_SOCIAL)) {
                throw ApiProblem::forbidden('scope_denied', 'The service token lacks the customer.social scope.');
            }
            $this->auth->authenticateAccessToken((string) $request->header('X-Customer-Token')) ?? throw ApiProblem::unauthenticated();
            RequestContext::set(RequestContext::SERVICE_TOKEN_ID, $row->id);
            RequestContext::set(self::VIA_SERVICE, '1');
        } else {
            throw ApiProblem::unauthenticated();
        }

        return $next($request);
    }
}
