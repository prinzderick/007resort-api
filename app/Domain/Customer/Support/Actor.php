<?php

namespace App\Domain\Customer\Support;

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
