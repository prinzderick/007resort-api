<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Auth\Grant;
use App\Domain\Identity\Auth\Scope;
use App\Support\Ids;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE authorization decision point (architecture/06 §1): staff x permission x scope, resolved from
 * role_assignment -> role_permission -> permission. It never looks at a role's name or code.
 *
 * Scope semantics:
 *  - no scope:               true if ANY active assignment bundles the permission ("holds it somewhere").
 *  - Scope::facility($id):   ORGANIZATION assignment of the facility's org, SITE assignment of its site,
 *                            or FACILITY_UNIT assignment on the facility or any of its ancestors.
 *  - Scope::site($id):       ORGANIZATION assignment of the site's org, or SITE assignment of that site.
 *  - Scope::organization($id): ORGANIZATION assignment only.
 * Inactive/soft-deleted assignments and inactive/deleted staff never grant anything.
 */
class PermissionChecker
{
    public function can(string $staffId, string $permission, ?Scope $scope = null): bool
    {
        return $this->grant($staffId, $permission, $scope) !== null;
    }

    /** Best grant (one that doesn't require approval wins) or null. */
    public function grant(string $staffId, string $permission, ?Scope $scope = null): ?Grant
    {
        return $this->query($staffId, $scope, $permission)->first();
    }

    /** All effective grants (used by GET /me). @return Collection<int, Grant> */
    public function effective(string $staffId): Collection
    {
        return $this->query($staffId, null, null);
    }

    /** @return Collection<int, Grant> */
    private function query(string $staffId, ?Scope $scope, ?string $permission): Collection
    {
        $q = DB::table('role_assignment as ra')
            ->join('staff as s', 's.id', '=', 'ra.staff_id')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ra.role_id')
            ->join('permission as p', 'p.id', '=', 'rp.permission_id')
            ->where('ra.staff_id', Ids::toBinary($staffId))
            ->where('ra.is_active', 1)->whereNull('ra.deleted_at')
            ->where('s.is_active', 1)->whereNull('s.deleted_at')
            ->orderBy('rp.requires_approval')->orderBy('p.code')
            ->select(['p.code', 'rp.requires_approval', 'ra.scope_level', 'ra.organization_id', 'ra.site_id', 'ra.facility_unit_id', 'ra.id as assignment_id']);

        if ($permission !== null) {
            $q->where('p.code', $permission);
        }

        if ($scope !== null) {
            [$orgId, $siteId, $chain] = $this->resolve($scope);
            $q->where(function ($w) use ($orgId, $siteId, $chain): void {
                $w->where(fn ($x) => $x->where('ra.scope_level', 'ORGANIZATION')->where('ra.organization_id', Ids::toBinary($orgId)));
                if ($siteId !== null) {
                    $w->orWhere(fn ($x) => $x->where('ra.scope_level', 'SITE')->where('ra.site_id', Ids::toBinary($siteId)));
                }
                if ($chain !== []) {
                    $w->orWhere(fn ($x) => $x->where('ra.scope_level', 'FACILITY_UNIT')
                        ->whereIn('ra.facility_unit_id', array_map(Ids::toBinary(...), $chain)));
                }
            });
        }

        return $q->get()->map(fn ($r) => new Grant(
            $r->code,
            (bool) $r->requires_approval,
            $r->scope_level,
            $r->organization_id ? Ids::fromBinary($r->organization_id) : null,
            $r->site_id ? Ids::fromBinary($r->site_id) : null,
            $r->facility_unit_id ? Ids::fromBinary($r->facility_unit_id) : null,
            Ids::fromBinary($r->assignment_id),
        ))->values();
    }

    /**
     * @return array{0: string, 1: ?string, 2: list<string>} organization id, site id, facility ancestor chain (self first)
     *
     * @throws ModelNotFoundException when the scope's resource doesn't exist
     */
    public function resolve(Scope $scope): array
    {
        if ($scope->facilityUnitId !== null) {
            $f = DB::table('facility_unit')->where('id', Ids::toBinary($scope->facilityUnitId))->first(['organization_id', 'site_id']);
            if (! $f) {
                throw (new ModelNotFoundException)->setModel('facility_unit');
            }
            $rows = DB::select(
                'WITH RECURSIVE chain (id, parent_id) AS (
                    SELECT id, parent_id FROM facility_unit WHERE id = ?
                    UNION ALL
                    SELECT f.id, f.parent_id FROM facility_unit f JOIN chain c ON f.id = c.parent_id
                 ) SELECT id FROM chain',
                [Ids::toBinary($scope->facilityUnitId)],
            );

            return [Ids::fromBinary($f->organization_id), Ids::fromBinary($f->site_id), array_map(fn ($r) => Ids::fromBinary($r->id), $rows)];
        }

        if ($scope->siteId !== null) {
            $s = DB::table('site')->where('id', Ids::toBinary($scope->siteId))->first(['organization_id']);
            if (! $s) {
                throw (new ModelNotFoundException)->setModel('site');
            }

            return [Ids::fromBinary($s->organization_id), Ids::normalize($scope->siteId), []];
        }

        return [Ids::normalize((string) $scope->organizationId), null, []];
    }
}
