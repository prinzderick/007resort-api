<?php

namespace Tests\Feature\Payments;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsWorld;
use Tests\Support\TestData;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use PaymentsWorld;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->session = $this->openSession();
        config(['payments.receipt.site_address' => '1 Resort Road, Otueke', 'payments.receipt.footer' => 'Thanks!']);
    }

    private function pay(string $total = '10750.0000', string $tax = '750.0000', string $rrn = 'RRN-777'): array
    {
        $order = $this->makeOrder($total, 'SERVED', null, null, $tax);
        $this->orderLine($order, 'Chapman', 2, '1000.0000');
        $r = $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $order, 'amount' => $total]],
            [['tenderType' => 'CASH', 'amount' => '5000.0000', 'tendered' => '6000.0000'], ['tenderType' => 'POS_TERMINAL', 'amount' => bcsub($total, '5000.0000', 4), 'reference' => $rrn]],
            $this->session,
        ), $this->auth($this->cashierToken))->assertCreated();

        return [$order, $r->json('receiptId')];
    }

    public function test_receipt_without_vat_registration_has_no_vat_line_or_tin(): void
    {
        $this->setVat(false);
        [$order, $rid] = $this->pay();
        $r = $this->getJson("/api/v1/receipts/{$rid}", $this->auth($this->cashierToken, null))->assertOk();
        $r->assertJsonPath('vatRegistered', false)->assertJsonPath('vatNumber', null)->assertJsonPath('taxTotal', '0.0000')
            ->assertJsonPath('total', '10750.0000')->assertJsonPath('facilityName', 'Restaurant')->assertJsonPath('siteAddress', '1 Resort Road, Otueke')
            ->assertJsonPath('businessName', 'Test Resort')->assertJsonPath('cashierName', 'Cashier1 Tester')->assertJsonPath('currency', 'NGN')
            ->assertJsonPath('changeGiven', '1000.0000')->assertJsonPath('footer', 'Thanks!')->assertJsonPath('reprintCount', 0);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-000001$/', $r->json('number'));
        $text = implode("\n", $r->json('printLines'));
        $this->assertStringNotContainsString('VAT', $text);
        $this->assertStringNotContainsString('TIN', $text);
        $this->assertStringContainsString('POS (RRN-777)', $text);
        $this->assertStringContainsString('Change', $text);
        $r->assertJsonPath('tenders.0.tenderType', 'CASH')->assertJsonPath('tenders.1.reference', 'RRN-777');
        $this->getJson("/api/v1/orders/{$order}/receipt", $this->auth($this->cashierToken, null))->assertOk()->assertJsonPath('id', $rid);
    }

    public function test_receipt_for_a_vat_registered_business_shows_tin_rate_and_vat_amount(): void
    {
        $this->setVat(true, 'TIN-99887766');
        [, $rid] = $this->pay();
        $r = $this->getJson("/api/v1/receipts/{$rid}", $this->auth($this->cashierToken, null))->assertOk();
        $r->assertJsonPath('vatRegistered', true)->assertJsonPath('vatNumber', 'TIN-99887766')->assertJsonPath('vatRatePercent', '7.5')->assertJsonPath('taxTotal', '750.0000');
        $text = implode("\n", $r->json('printLines'));
        $this->assertStringContainsString('TIN: TIN-99887766', $text);
        $this->assertMatchesRegularExpression('/VAT \(7\.5%\)\s+N750\.00/', $text);
        foreach ($r->json('printLines') as $l) {
            $this->assertLessThanOrEqual(48, mb_strlen($l));
        }
    }

    public function test_a_receipt_is_a_frozen_snapshot_later_vat_changes_do_not_rewrite_it(): void
    {
        $this->setVat(false);
        [, $rid] = $this->pay();
        $this->setVat(true);
        $this->getJson("/api/v1/receipts/{$rid}", $this->auth($this->cashierToken, null))->assertJsonPath('vatRegistered', false)->assertJsonPath('taxTotal', '0.0000');
    }

    public function test_receipt_numbers_are_sequential_per_site_and_day(): void
    {
        $a = $this->pay()[1];
        $b = $this->pay(rrn: 'RRN-778')[1];
        $na = $this->getJson("/api/v1/receipts/{$a}", $this->auth($this->cashierToken, null))->json('number');
        $nb = $this->getJson("/api/v1/receipts/{$b}", $this->auth($this->cashierToken, null))->json('number');
        $this->assertSame((int) substr($na, -6) + 1, (int) substr($nb, -6));
        $this->assertSame(substr($na, 0, 13), substr($nb, 0, 13));
    }

    public function test_reprint_needs_its_own_permission_marks_a_duplicate_and_is_counted_and_audited(): void
    {
        [, $rid] = $this->pay();
        $role = TestData::customRole('VIEW_ONLY', 'Viewer', ['receipt.view']);
        $v = TestData::staff($this->t, 'viewer1');
        TestData::assignRole($v, $role, 'SITE');
        $viewer = $this->loginToken('viewer1');
        $this->getJson("/api/v1/receipts/{$rid}", $this->auth($viewer, null))->assertOk()->assertJsonPath('duplicate', false);
        $this->getJson("/api/v1/receipts/{$rid}?reprint=true", $this->auth($viewer, null))->assertForbidden()->assertJsonPath('permission', 'receipt.reprint');

        $r = $this->getJson("/api/v1/receipts/{$rid}?reprint=true", $this->auth($this->cashierToken, null))->assertOk();
        $r->assertJsonPath('duplicate', true)->assertJsonPath('reprintCount', 1);
        $this->assertStringContainsString('DUPLICATE COPY', implode("\n", $r->json('printLines')));
        $this->getJson("/api/v1/receipts/{$rid}?reprint=true", $this->auth($this->cashierToken, null))->assertJsonPath('reprintCount', 2);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'receipt.reprint')->count());
        $this->getJson("/api/v1/receipts/{$rid}", $this->auth($this->cashierToken, null))->assertJsonPath('duplicate', false)->assertJsonPath('reprintCount', 2);
        $this->getJson('/api/v1/receipts/'.Ids::uuid7(), $this->auth($this->cashierToken, null))->assertNotFound();
    }
}
