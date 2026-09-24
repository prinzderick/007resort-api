<?php

namespace App\Domain\Membership\Models;

use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use App\Support\Money\MoneyString;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    use HasUuidV7;

    protected $table = 'membership_plan';

    protected array $uuidColumns = ['organization_id'];

    protected function casts(): array
    {
        return [
            'price' => MoneyString::class,
            'is_active' => 'boolean',
            'property_wide' => 'boolean',
            'booking_privileges' => 'array',
            'duration_days' => 'integer',
            'visit_limit' => 'integer',
            'guest_allowance' => 'integer',
            'booking_advance_days' => 'integer',
            'grace_period_days' => 'integer',
            'renewal_notice_days' => 'integer',
        ];
    }

    public function coverage(): HasMany
    {
        return $this->hasMany(PlanCoverage::class, 'plan_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $facilityIds = $this->coverage->map(fn (PlanCoverage $c) => $c->facility_unit_id)->values()->all();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'durationDays' => $this->duration_days,
            'price' => $this->price,
            'currency' => $this->currency,
            'facilityIds' => $facilityIds,
            'propertyWide' => (bool) $this->property_wide,
            'visitLimit' => $this->visit_limit,
            'guestAllowance' => $this->guest_allowance,
            'memberDiscountPercent' => number_format((float) $this->member_discount_percent, 2, '.', ''),
            'bookingAdvanceDays' => $this->booking_advance_days,
            'bookingPrivileges' => $this->booking_privileges ?? [],
            'gracePeriodDays' => $this->grace_period_days,
            'renewalNoticeDays' => $this->renewal_notice_days,
            'active' => (bool) $this->is_active,
            'rowVersion' => (int) $this->row_version,
        ];
    }
}
