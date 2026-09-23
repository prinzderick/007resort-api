<?php

namespace Tests\Feature\Payments;

use Tests\Support\PaymentsWorld;
use Tests\Support\TestData;
use Tests\TestCase;

/**
 * GET /payments and GET /cash-sessions without a facility filter: your own rows, unless the
 * permission is held site-wide (owner/accountant/manager), in which case it is the whole property.
 * (The admin portal's finance and shift screens rely on this.)
 */
class SiteWideListingTest extends TestCase
{
    use PaymentsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_site_wide_viewer_sees_the_whole_property_but_a_facility_scoped_cashier_only_their_own(): void
    {
        $this->postJson('/api/v1/cash-sessions', ['facilityId' => $this->facility, 'openingFloat' => '1000.0000'], $this->auth($this->cashierToken))->assertCreated();
        $order = $this->makeOrder('2000.0000');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '2000.0000']], [['tenderType' => 'CASH', 'amount' => '2000.0000']]), $this->auth($this->cashierToken))->assertCreated();

        $other = TestData::staff($this->t, 'cashier2');
        TestData::assign($other, 'CASHIER', 'FACILITY_UNIT', $this->facility);
        $otherToken = $this->loginToken('cashier2');

        $this->getJson('/api/v1/payments', $this->auth($this->managerToken, null))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.amount', '2000.0000');
        $this->getJson('/api/v1/cash-sessions', $this->auth($this->managerToken, null))->assertOk()->assertJsonCount(1, 'items');
        // held only at one facility (not site-wide): unfiltered = own takings only
        $this->getJson('/api/v1/payments', $this->auth($otherToken, null))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/v1/cash-sessions', $this->auth($otherToken, null))->assertOk()->assertJsonCount(0, 'items');
    }
}
