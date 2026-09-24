<?php

namespace Tests\Feature\Config;

use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsWorld;
use Tests\Support\TestData;
use Tests\TestCase;

/** The settings must actually change runtime behaviour: disabled tenders are refused, receipts use the admin's settings. */
class SettingsRuntimeTest extends TestCase
{
    use PaymentsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private ?string $session = null;

    private function pay(string $order, array $tender)
    {
        $this->session ??= $this->openSession();

        return $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '5000.0000']], [$tender + ['amount' => '5000.0000']], $this->session), $this->auth($this->cashierToken));
    }

    public function test_a_disabled_tender_is_refused_and_enabled_ones_still_work(): void
    {
        DB::table('facility_payment_method')->insert([
            ['facility_unit_id' => TestData::bin($this->facility), 'method' => 'CASH', 'is_enabled' => 1],
            ['facility_unit_id' => TestData::bin($this->facility), 'method' => 'CARD', 'is_enabled' => 0],
            ['facility_unit_id' => TestData::bin($this->facility), 'method' => 'TRANSFER', 'is_enabled' => 0],
            ['facility_unit_id' => TestData::bin($this->facility), 'method' => 'POS_TERMINAL', 'is_enabled' => 0],
            ['facility_unit_id' => TestData::bin($this->facility), 'method' => 'PAYSTACK', 'is_enabled' => 1],
        ]);
        $order = $this->makeOrder('5000.0000');
        $r = $this->pay($order, ['tenderType' => 'POS_TERMINAL', 'reference' => 'RRN000111222'])->assertStatus(422);
        $this->assertSame('payment_method_disabled', $r->json('code'));
        $this->assertArrayHasKey('tenders.0.tenderType', $r->json('errors'));
        $this->assertSame('SERVED', $this->orderStatus($order), 'nothing was taken');
        $this->pay($order, ['tenderType' => 'CASH', 'tendered' => '5000.0000'])->assertCreated();
        $this->assertSame('SETTLED', $this->orderStatus($order));
    }

    public function test_receipts_use_the_admin_receipt_settings(): void
    {
        $org = TestData::bin($this->t['org']);
        DB::table('receipt_setting')->insert(['organization_id' => $org, 'business_name' => 'Configured Resort Ltd', 'address' => '9 Admin Street', 'phone' => '+2340000', 'header_note' => 'VAT inclusive',
            'footer' => 'Configured footer', 'logo_url' => 'https://cdn.example.com/l.png', 'show_tin' => 0, 'paper_columns' => 32]);
        $order = $this->makeOrder('5000.0000');
        $rid = $this->pay($order, ['tenderType' => 'CASH', 'tendered' => '5000.0000'])->assertCreated()->json('receiptId');
        $rc = $this->getJson("/api/v1/receipts/{$rid}", $this->auth($this->cashierToken, null))->assertOk();
        $this->assertSame('Configured Resort Ltd', $rc->json('businessName'));
        $this->assertSame('9 Admin Street', $rc->json('siteAddress'));
        $this->assertSame('Configured footer', $rc->json('footer'));
        $this->assertSame('https://cdn.example.com/l.png', $rc->json('logoUrl'));
        $this->assertSame('VAT inclusive', $rc->json('headerNote'));
        foreach ($rc->json('printLines') as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line), "58mm paper: '{$line}'");
        }
    }
}
