<?php

namespace Tests\Unit;

use App\Domain\Payments\Support\ReceiptRenderer;
use Tests\TestCase;

class ReceiptRendererTest extends TestCase
{
    private function payload(bool $vat): array
    {
        return [
            'number' => 'RCP-20260923-000045', 'businessName' => '007 Resort & Spa', 'siteName' => 'Otueke Site', 'siteAddress' => '1 Resort Road, Otueke',
            'facilityName' => 'Restaurant', 'issuedAt' => '2026-09-23T10:15:30.123456Z', 'cashierName' => 'Amaka O.', 'terminal' => 'POS-1',
            'orderNumbers' => ['RST1-000123'], 'tableLabel' => 'T4',
            'lines' => [
                ['name' => 'Jollof rice with fried chicken and plantain (large)', 'quantity' => 2, 'unitPrice' => '3500.0000', 'lineTotal' => '7000.0000'],
                ['name' => 'Chapman', 'quantity' => 1, 'unitPrice' => '2000.0000', 'lineTotal' => '2000.0000'],
            ],
            'subtotal' => '8372.0930', 'discountTotal' => '0.0000', 'taxTotal' => $vat ? '627.9070' : '0.0000', 'total' => '9000.0000',
            'tenders' => [['tenderType' => 'CASH', 'amount' => '9000.0000', 'reference' => null, 'tendered' => '10000.0000']],
            'changeGiven' => '1000.0000', 'balanceDue' => '0.0000', 'vatRegistered' => $vat, 'vatRatePercent' => '7.5', 'vatNumber' => $vat ? 'TIN-12345678' : null,
            'footer' => 'Thank you', 'duplicate' => false,
        ];
    }

    public function test_vat_registered_receipt_prints_tin_and_vat_line(): void
    {
        $text = implode("\n", ReceiptRenderer::lines($this->payload(true)));
        $this->assertStringContainsString('TIN: TIN-12345678', $text);
        $this->assertMatchesRegularExpression('/VAT \(7\.5%\)\s+N627\.91/', $text);
    }

    public function test_non_vat_receipt_has_neither_tin_nor_vat_line(): void
    {
        $text = implode("\n", ReceiptRenderer::lines($this->payload(false)));
        $this->assertStringNotContainsString('TIN', $text);
        $this->assertStringNotContainsString('VAT', $text);
        $this->assertStringContainsString('TOTAL', $text);
    }

    public function test_80mm_layout_never_exceeds_48_columns_and_shows_tenders_change_staff_terminal(): void
    {
        $lines = ReceiptRenderer::lines($this->payload(true), 48);
        foreach ($lines as $l) {
            $this->assertLessThanOrEqual(48, mb_strlen($l), $l);
        }
        $text = implode("\n", $lines);
        $this->assertStringContainsString('Cash', $text);
        $this->assertStringContainsString('Tendered', $text);
        $this->assertMatchesRegularExpression('/Change\s+N1,000\.00/', $text);
        $this->assertStringContainsString('Served by: Amaka O.', $text);
        $this->assertStringContainsString('Terminal: POS-1', $text);
        $this->assertStringContainsString('2 x Jollof rice', $text);
        // narrow 58mm paper
        foreach (ReceiptRenderer::lines($this->payload(true), 32) as $l) {
            $this->assertLessThanOrEqual(32, mb_strlen($l), $l);
        }
    }

    public function test_duplicate_copies_are_marked(): void
    {
        $p = $this->payload(false);
        $p['duplicate'] = true;
        $this->assertStringContainsString('DUPLICATE COPY', implode("\n", ReceiptRenderer::lines($p)));
    }
}
