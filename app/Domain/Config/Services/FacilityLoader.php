<?php

namespace App\Domain\Config\Services;

use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** Loads a facility of the caller's site (404 otherwise). `lock` = SELECT ... FOR UPDATE inside the caller's transaction. */
class FacilityLoader
{
    public function find(string $id, bool $lock = false): object
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Facility was not found.');
        }
        $q = DB::table('facility_unit')->where('id', Ids::toBinary($id))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->whereNull('deleted_at');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first() ?? throw ApiProblem::notFound('not_found', 'Facility was not found.');
    }

    public function lock(string $id): object
    {
        return $this->find($id, true);
    }
}
