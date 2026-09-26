<?php

namespace App\Domain\Customer\Support;

use App\Domain\Guest\Services\GuestAccess;
use App\Domain\Guest\Support\GuestSession;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;

/**
 * Who is calling: `staff` (bearer r7a_), `customer` (r7c_) or `service` (r7s_, read-only). Exactly one applies per request.
 * Endpoints that admit customers/service tokens use `auth:staff,customer[,service]` and consult this class; endpoints that only
 * carry `auth:staff` reject every other credential type before any controller code runs.
 */
final class Actor
{
    public static function isStaff(): bool
    {
        return RequestContext::staffId() !== null;
    }

    public static function isCustomer(): bool
    {
        return RequestContext::customerId() !== null;
    }

    public static function isService(): bool
    {
        return RequestContext::serviceTokenId() !== null;
    }

    /** True for the anonymous-ish public actors (customer or website service token): the ones whose reach must be narrowed. */
    public static function isPublic(): bool
    {
        return ! self::isStaff() && (self::isCustomer() || self::isService());
    }

    /** Does the website service token carry this scope (comma-set in `service_token.scope`)? */
    public static function serviceCan(string $scope): bool
    {
        return self::isService() && in_array($scope, array_map('trim', explode(',', (string) RequestContext::get(RequestContext::SERVICE_SCOPE))), true);
    }

    /**
     * The guest order the request's `X-Order-Token` authorises (guest checkout, docs/GUEST_CHECKOUT.md), or null when no token was sent.
     * Only a service token with `public.checkout` may present one. A bad token is 401 `order_token_invalid`, an old one `order_token_expired`.
     */
    public static function guest(): ?GuestSession
    {
        if (! self::isService() || self::isStaff() || self::isCustomer()) {
            return null;
        }
        $token = trim((string) request()->header('X-Order-Token', ''));
        if ($token === '') {
            return null;
        }
        if (! self::serviceCan('public.checkout')) {
            throw ApiProblem::forbidden('scope_denied', 'The service token lacks the public.checkout scope.');
        }
        $attr = 'r007.guest_session';
        $cached = request()->attributes->get($attr);
        if ($cached instanceof GuestSession) {
            return $cached;
        }
        $r = app(GuestAccess::class)->resolve($token);
        if ($r instanceof GuestSession) {
            request()->attributes->set($attr, $r);

            return $r;
        }
        throw ApiProblem::unauthenticated($r === 'expired' ? 'order_token_expired' : 'order_token_invalid', 'That order link is not valid any more. Look your order up again with its reference and email.');
    }

    public static function isGuest(): bool
    {
        return self::guest() !== null;
    }

    public static function customerId(): ?string
    {
        return RequestContext::customerId();
    }

    /** @throws ApiProblem 403 unless a signed-in customer */
    public static function requireCustomer(): string
    {
        return self::customerId() ?? throw ApiProblem::forbidden('customer_required', 'Sign in with a customer account to do this.');
    }

    /** Ownership failures look exactly like "does not exist": no probing of other customers' ids. */
    public static function notYours(string $what): ApiProblem
    {
        return ApiProblem::notFound('not_found', "{$what} not found.");
    }
}
