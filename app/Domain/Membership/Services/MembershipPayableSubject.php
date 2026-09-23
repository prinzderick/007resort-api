<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\MembershipPlan;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * What Payments needs to take money for a membership (its `PayableSubjectResolver` contract: resolve('MEMBERSHIP', id)).
 * Kept as a plain class so this branch does not depend on the Payments module; once Payments is merged, bind a resolver
 * that delegates here for type MEMBERSHIP (Booking delegates for BOOKING).
 *
 * amountDue: PENDING_PAYMENT -> the price snapshotted at purchase; ACTIVE/PENDING_RENEWAL/EXPIRED -> the plan's current
 * price (a renewal). Other states are not payable. facilityId = a facility for scoping the payment (first coverage row, else null).
 */
class MembershipPayableSubject
{
    /** @return array{amountDue: string, facilityId: ?string}|null */
    public function resolve(string $type, string $id): ?array
    {
        if ($type !== 'MEMBERSHIP') {
            return null;
        }
        $m = Membership::query()->find($id);
        if ($m === null) {
            return null;
        }
        if ($m->status === Membership::PENDING_PAYMENT) {
            $amount = $m->price_paid;
        } elseif (in_array($m->status, [Membership::ACTIVE, Membership::PENDING_RENEWAL, Membership::EXPIRED], true)) {
            $amount = MembershipPlan::query()->find($m->plan_id)?->price;
        } else {
            return null;
        }
        $facility = DB::table('plan_coverage')->where('plan_id', Ids::toBinary($m->plan_id))->orderBy('id')->value('facility_unit_id');

        return $amount === null ? null : ['amountDue' => $amount, 'facilityId' => $facility ? Ids::fromBinary($facility) : null];
    }
}
