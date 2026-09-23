<?php

namespace App\Support\Api;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Permission checks for services/controllers (permission-based ONLY, never role names). */
final class Authz
{
    public static function staffId(): string
    {
        return RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
    }

    public static function can(string $permission, ?string $facilityId = null, ?string $staffId = null): bool
    {
        $staffId ??= self::staffId();
        $checker = app(PermissionChecker::class);
        try {
            return $checker->can($staffId, $permission, $facilityId === null ? null : Scope::facility($facilityId));
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    public static function require(string $permission, ?string $facilityId = null): void
    {
        if (! self::can($permission, $facilityId)) {
            throw ApiProblem::permissionDenied($permission);
        }
    }
}
