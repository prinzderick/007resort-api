<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\MembershipPlan;
use App\Domain\Membership\Models\PlanCoverage;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PlanService
{
    /** @param array<string, mixed> $d validated input */
    public function create(array $d): MembershipPlan
    {
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');

        try {
            return DB::transaction(function () use ($d, $org): MembershipPlan {
                $plan = MembershipPlan::create($this->columns($d) + ['organization_id' => $org, 'code' => strtoupper($d['code'])]);
                $this->syncCoverage($plan, $d['facilityIds'] ?? [], (bool) ($d['propertyWide'] ?? false));
                Audit::record('membership.plan.create', 'MembershipPlan', $plan->id, null, $this->snapshot($plan->refresh()->load('coverage')));

                return $plan->load('coverage');
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::conflict('plan_code_taken', "A plan with code {$d['code']} already exists.");
        }
    }

    /** @param array<string, mixed> $d validated partial input */
    public function update(string $id, array $d): MembershipPlan
    {
        return DB::transaction(function () use ($id, $d): MembershipPlan {
            $plan = MembershipPlan::query()->lockForUpdate()->find($id) ?? throw ApiProblem::notFound('plan_not_found', 'Plan not found.');
            $plan->load('coverage');
            $old = $this->snapshot($plan);
            $cols = $this->columns($d, partial: true);
            if ($cols !== []) {
                $plan->update($cols + ['row_version' => $plan->row_version + 1]);
            }
            if (array_key_exists('facilityIds', $d) || array_key_exists('propertyWide', $d)) {
                $this->syncCoverage($plan, $d['facilityIds'] ?? $plan->coverage->pluck('facility_unit_id')->all(), (bool) ($d['propertyWide'] ?? $plan->property_wide));
            }
            $plan->refresh()->load('coverage');
            Audit::record('membership.plan.update', 'MembershipPlan', $plan->id, $old, $this->snapshot($plan));

            return $plan;
        });
    }

    /** @param array<string, mixed> $d */
    private function columns(array $d, bool $partial = false): array
    {
        $map = [
            'name' => 'name', 'description' => 'description', 'durationDays' => 'duration_days', 'visitLimit' => 'visit_limit',
            'guestAllowance' => 'guest_allowance', 'bookingAdvanceDays' => 'booking_advance_days', 'gracePeriodDays' => 'grace_period_days',
            'renewalNoticeDays' => 'renewal_notice_days', 'active' => 'is_active', 'propertyWide' => 'property_wide',
            'bookingPrivileges' => 'booking_privileges',
        ];
        $out = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $d)) {
                $out[$col] = $d[$in];
            }
        }
        if (array_key_exists('price', $d)) {
            $out['price'] = Money::of($d['price'])->amount;
        }
        if (array_key_exists('memberDiscountPercent', $d)) {
            $out['member_discount_percent'] = $d['memberDiscountPercent'];
        }
        if (! $partial) {
            $out += ['visit_limit' => null];
        }

        return $out;
    }

    /** @param list<string> $facilityIds */
    private function syncCoverage(MembershipPlan $plan, array $facilityIds, bool $propertyWide): void
    {
        $plan->coverage()->delete();
        if ($propertyWide) {
            return;
        }
        foreach (array_unique($facilityIds) as $fid) {
            $ok = DB::table('facility_unit')->where('id', Ids::toBinary($fid))->where('organization_id', Ids::toBinary($plan->organization_id))->exists();
            if (! $ok) {
                throw ApiProblem::unprocessable('validation_failed', 'Unknown facility in facilityIds.', [['field' => 'facilityIds', 'code' => 'unknown_facility', 'message' => "Facility {$fid} not found."]]);
            }
            PlanCoverage::create(['plan_id' => $plan->id, 'facility_unit_id' => $fid]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(MembershipPlan $p): array
    {
        return $p->toApi();
    }
}
