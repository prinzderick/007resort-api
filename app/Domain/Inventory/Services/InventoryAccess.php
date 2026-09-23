<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Location-scoped permission checks. A location belongs to a facility (facility subtree scope) or, for the Main
 * Store, to the site. Permission-based only (architecture/06) — never a role name.
 */
class InventoryAccess
{
    public function __construct(private readonly PermissionChecker $checker) {}

    public function can(string $permission, object $location, ?string $staffId = null): bool
    {
        $staffId ??= RequestContext::staffId();
        if ($staffId === null) {
            return false;
        }

        return $this->checker->can($staffId, $permission, $this->scopeOf($location));
    }

    public function canAtLocationId(string $permission, string $locationId, ?string $staffId = null): bool
    {
        return $this->can($permission, $this->locationRow($locationId), $staffId);
    }

    public function authorize(string $permission, string $locationId): object
    {
        $location = $this->locationRow($locationId);
        if (! $this->can($permission, $location)) {
            throw ApiProblem::permissionDenied($permission);
        }

        return $location;
    }

    /** @return list<string>|null null = every location (permission held at site/org level) */
    public function visibleLocationIds(string $permission): ?array
    {
        $staffId = RequestContext::staffId();
        $rows = DB::table('stock_location')->get(['id', 'facility_unit_id', 'site_id', 'organization_id']);
        $ids = [];
        $all = true;
        foreach ($rows as $r) {
            if ($this->checker->can((string) $staffId, $permission, $this->scopeOf($r))) {
                $ids[] = Ids::fromBinary($r->id);
            } else {
                $all = false;
            }
        }

        return $all ? null : $ids;
    }

    private function scopeOf(object $location): Scope
    {
        return $location->facility_unit_id !== null
            ? Scope::facility(Ids::fromBinary($location->facility_unit_id))
            : Scope::site(Ids::fromBinary($location->site_id));
    }

    public function locationRow(string $locationId): object
    {
        if (! Ids::isUuid($locationId)) {
            throw ApiProblem::notFound('not_found', 'That stock location does not exist.');
        }
        $row = DB::table('stock_location')->where('id', Ids::toBinary($locationId))->first();
        if (! $row) {
            throw ApiProblem::notFound('not_found', 'That stock location does not exist.');
        }

        return $row;
    }
}
