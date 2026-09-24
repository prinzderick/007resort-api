<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Support\CollectionRules;
use App\Support\Api\Authz;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Who may hold cash (docs/WAITER_COLLECTION.md section 6). Effective policy of a waiter at a facility = per-staff override
 * (`staff_collection_policy`: INHERIT | ALLOW | DENY + optional personal limit), else the facility rules
 * `waiter_cash_holding` / `waiter_cash_in_hand_limit`. Cash holding is OFF unless something turns it on.
 */
class CollectionPolicyService
{
    public function __construct(private readonly CollectionRules $rules, private readonly PermissionChecker $permissions) {}

    /**
     * @return array<string, mixed> CollectionPolicy
     */
    public function effective(string $staffId, ?string $facilityId): array
    {
        $o = DB::table('staff_collection_policy')->where('staff_id', Ids::toBinary($staffId))->first();
        $override = $o->cash_holding ?? 'INHERIT';
        $personal = $o !== null && $o->cash_limit !== null ? Money::normalize((string) $o->cash_limit) : null;
        $fr = $facilityId !== null ? $this->rules->forFacility($facilityId) : null;
        $facilityHolding = $fr['cashHolding'] ?? false;
        $facilityLimit = $fr['cashLimit'] ?? null;

        [$allowed, $source] = match ($override) {
            'ALLOW' => [true, 'staff'],
            'DENY' => [false, 'staff'],
            default => [$facilityHolding, 'facility'],
        };
        $limit = null;
        $limitSource = null;
        if ($allowed) {
            if ($personal !== null && $override === 'ALLOW') {
                [$limit, $limitSource] = [$personal, 'staff'];
            } elseif ($facilityLimit !== null) {
                [$limit, $limitSource] = [$facilityLimit, 'facility'];
            }
        }
        $enabled = $fr['collectionEnabled'] ?? false;
        $tenders = $enabled ? array_values(array_filter(['CARD_TERMINAL', 'TRANSFER', 'PAY_LINK', $allowed ? 'CASH' : null])) : [];

        return [
            'staffId' => $staffId,
            'facilityId' => $facilityId,
            'collectionEnabled' => $enabled,
            'cashHolding' => ['allowed' => $allowed, 'source' => $source, 'limit' => $limit, 'limitSource' => $limitSource],
            'allowedTenders' => $tenders,
            'staffOverride' => ['cashHolding' => $override, 'cashLimit' => $personal],
            'facilityRule' => ['waiterCashHolding' => $facilityHolding, 'waiterCashInHandLimit' => $facilityLimit],
        ];
    }

    /** Facility the policy is evaluated for when the caller does not say: the staff member's active tablet checkout, else their first facility. */
    public function defaultFacility(string $staffId): ?string
    {
        $c = DB::table('tablet_checkout')->where('staff_id', Ids::toBinary($staffId))->whereNull('checked_in_at')->orderByDesc('checked_out_at')->value('facility_unit_id');
        if ($c !== null) {
            return Ids::fromBinary($c);
        }

        return $this->permissions->facilityIds($staffId)[0] ?? null;
    }

    /** @param array{cashHolding?: string, cashLimit?: ?string} $in */
    public function update(string $staffId, array $in): array
    {
        $staff = DB::table('staff')->where('id', Ids::toBinary($staffId))->first() ?? throw ApiProblem::notFound('not_found', 'Staff member not found.');
        $facility = $this->defaultFacility($staffId);

        return DB::transaction(function () use ($staffId, $in, $staff, $facility) {
            $bin = Ids::toBinary($staffId);
            $row = DB::table('staff_collection_policy')->where('staff_id', $bin)->lockForUpdate()->first();
            $old = ['cashHolding' => $row->cash_holding ?? 'INHERIT', 'cashLimit' => $row && $row->cash_limit !== null ? Money::normalize((string) $row->cash_limit) : null];
            $new = $old;
            if (array_key_exists('cashHolding', $in)) {
                $new['cashHolding'] = $in['cashHolding'];
            }
            if (array_key_exists('cashLimit', $in)) {
                $new['cashLimit'] = $in['cashLimit'] === null ? null : Money::normalize($in['cashLimit']);
            }
            $values = ['cash_holding' => $new['cashHolding'], 'cash_limit' => $new['cashLimit'], 'updated_by' => Ids::toBinary(Authz::staffId())];
            if ($row === null) {
                DB::table('staff_collection_policy')->insert(['staff_id' => $bin, 'row_version' => 1] + $values);
            } else {
                DB::table('staff_collection_policy')->where('staff_id', $bin)->update($values + ['row_version' => $row->row_version + 1]);
            }
            $orgId = Ids::fromBinary($staff->organization_id);
            $siteId = Ids::fromBinary($staff->site_id);
            Audit::record('staff.collection_policy.update', 'Staff', $staffId, old: $old, new: $new, organizationId: $orgId, siteId: $siteId, facilityUnitId: $facility);
            Outbox::record('StaffCollectionPolicyChanged', 'Staff', $staffId, ['staffId' => $staffId] + $new, entityVersion: ($row->row_version ?? 0) + 1, organizationId: $orgId, siteId: $siteId);

            return $this->effective($staffId, $facility);
        });
    }

    public function cashAllowed(string $staffId, string $facilityId): bool
    {
        return $this->effective($staffId, $facilityId)['cashHolding']['allowed'];
    }

    /** Caller may read a policy: their own, or `staff.manage`. */
    public function assertCanView(string $staffId): void
    {
        if (Authz::staffId() !== $staffId && ! $this->permissions->can(Authz::staffId(), 'staff.manage')) {
            throw ApiProblem::permissionDenied('staff.manage');
        }
    }
}
