<?php

namespace App\Domain\Customer\Services;

use App\Domain\Membership\Services\MembershipPayableSubject;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** BOOKING -> Booking's resolver, MEMBERSHIP -> Membership's (a membership with no covered facility is scoped to the site's reception/first facility). */
final class CompositePayableSubjectResolver implements PayableSubjectResolver
{
    public function __construct(private readonly PayableSubjectResolver $bookings) {}

    public function resolve(string $type, string $id): ?array
    {
        if ($type === 'BOOKING') {
            return $this->bookings->resolve($type, $id);
        }
        $r = app(MembershipPayableSubject::class)->resolve($type, $id);
        if ($r === null) {
            return null;
        }
        $r['facilityId'] ??= $this->fallbackFacility();

        return $r['facilityId'] === null ? null : $r;
    }

    private function fallbackFacility(): ?string
    {
        $site = Tenant::siteId();
        $q = DB::table('facility_unit')->whereNull('deleted_at')->where('is_active', 1);
        $site && $q->where('site_id', Ids::toBinary($site));
        $row = (clone $q)->where('code', 'RECEPTION')->first(['id']) ?? $q->orderBy('created_at')->first(['id']);

        return $row ? Ids::fromBinary($row->id) : null;
    }
}
