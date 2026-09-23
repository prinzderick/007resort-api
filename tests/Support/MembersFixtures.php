<?php

namespace Tests\Support;

use App\Domain\Membership\Models\MembershipPlan;
use App\Domain\Membership\Models\PlanCoverage;

/** Shared arrange helpers for the Membership / Attendance / Reporting suites. */
trait MembersFixtures
{
    protected array $t;

    protected function bootTenant(): void
    {
        $this->t = TestData::tenant();
    }

    protected function login(string $username): string
    {
        return $this->postJson('/api/v1/auth/staff/login', ['username' => $username, 'password' => TestData::PASSWORD])->json('accessToken');
    }

    /** Create a staff member with a seeded role bundle and return [staff, bearer token]. */
    protected function actor(string $username, string $role = 'MANAGER', string $level = 'SITE', ?string $facilityId = null): array
    {
        $staff = TestData::staff($this->t, $username);
        TestData::assign($staff, $role, $level, $facilityId);

        return [$staff, $this->login($username)];
    }

    /** Actor holding exactly the listed permissions (custom role, any name). */
    protected function actorWith(string $username, array $permissions, string $level = 'SITE', ?string $facilityId = null): array
    {
        $staff = TestData::staff($this->t, $username);
        TestData::assignRole($staff, TestData::customRole('R_'.strtoupper($username), ucfirst($username).' role', $permissions), $level, $facilityId);

        return [$staff, $this->login($username)];
    }

    protected function plan(array $over = [], array $facilityIds = []): MembershipPlan
    {
        $plan = MembershipPlan::create($over + [
            'organization_id' => $this->t['org'], 'code' => 'P'.random_int(1000, 999999), 'name' => 'Test plan', 'price' => '50000.0000', 'currency' => 'NGN',
            'duration_days' => 30, 'visit_limit' => null, 'guest_allowance' => 0, 'member_discount_percent' => '10.00', 'grace_period_days' => 0,
            'renewal_notice_days' => 7, 'property_wide' => $facilityIds === [], 'is_active' => true,
        ]);
        foreach ($facilityIds as $f) {
            PlanCoverage::create(['plan_id' => $plan->id, 'facility_unit_id' => $f]);
        }

        return $plan;
    }

    protected function idem(string $token, ?string $key = null): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key ?? bin2hex(random_bytes(8)), 'Accept' => 'application/json'];
    }
}
