<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Support\Fmt;
use App\Domain\Payments\Support\ReceiptRenderer;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Receipts (contract `Receipt`): an immutable, render-ready snapshot taken inside the payment's transaction.
 * Clients never compute totals. VAT: the snapshot records `vatRegistered`; the VAT line / TIN are only emitted when the
 * organization was VAT registered at issue time (ADR-0011).
 */
class ReceiptService
{
    /**
     * @param  array{organizationId: string, siteId: string, facilityId: string, groupId: string, staffId: ?string, deviceId: ?string,
     *     orders: list<array<string, mixed>>, details: array<string, array<string, mixed>>,
     *     tenders: list<array{tenderType: string, amount: string, reference: ?string, tendered: ?string, changeGiven: string}>,
     *     amountPaid: string, balanceDue: string}  $ctx
     * @return array{id: string, number: string}
     */
    public function issue(array $ctx): array
    {
        $issuedAt = CarbonImmutable::now('UTC');
        $id = Ids::uuid7();
        $number = $this->nextNumber($ctx['siteId'], $issuedAt);

        $subtotal = $discount = $tax = $total = '0.0000';
        $lines = [];
        $numbers = [];
        $table = null;
        foreach ($ctx['orders'] as $o) {
            $subtotal = bcadd($subtotal, $o['subtotal'], 4);
            $discount = bcadd($discount, $o['discountTotal'], 4);
            $tax = bcadd($tax, $o['taxTotal'], 4);
            $total = bcadd($total, $o['total'], 4);
            $numbers[] = $o['number'];
            $d = $ctx['details'][$o['id']] ?? ['lines' => [], 'tableLabel' => null];
            $table ??= $d['tableLabel'];
            foreach ($d['lines'] as $l) {
                $lines[] = $l;
            }
        }
        $change = '0.0000';
        foreach ($ctx['tenders'] as $t) {
            $change = bcadd($change, $t['changeGiven'], 4);
        }

        $vat = DB::table('organization_tax_setting')->where('organization_id', Ids::toBinary($ctx['organizationId']))->first();
        $vatRegistered = $vat !== null && (bool) $vat->vat_enabled;
        $org = DB::table('organization')->where('id', Ids::toBinary($ctx['organizationId']))->value('name');
        $site = DB::table('site')->where('id', Ids::toBinary($ctx['siteId']))->value('name');
        $facility = DB::table('facility_unit')->where('id', Ids::toBinary($ctx['facilityId']))->value('name');
        $cashier = 'Online';
        if ($ctx['staffId'] !== null) {
            $s = DB::table('staff')->where('id', Ids::toBinary($ctx['staffId']))->first(['first_name', 'last_name']);
            $cashier = $s ? trim($s->first_name.' '.$s->last_name) : 'Staff';
        }
        $terminal = $ctx['deviceId'] !== null ? DB::table('device')->where('id', Ids::toBinary($ctx['deviceId']))->value('name') : null;

        $payload = [
            'facilityId' => $ctx['facilityId'],
            'facilityName' => (string) $facility,
            'businessName' => (string) (config('payments.receipt.business_name') ?: $org),
            'siteName' => (string) $site,
            'siteAddress' => (string) config('payments.receipt.site_address', ''),
            'issuedAt' => Fmt::iso($issuedAt->format('Y-m-d H:i:s.u')),
            'cashierName' => $cashier,
            'terminal' => $terminal,
            'orderIds' => array_map(fn ($o) => $o['id'], $ctx['orders']),
            'orderNumbers' => $numbers,
            'tableLabel' => $table,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discountTotal' => $discount,
            // Contract: taxTotal is always present. It is 0 (and no VAT line is printed) unless the business is VAT registered.
            'taxTotal' => $vatRegistered ? $tax : '0.0000',
            'total' => $total,
            'amountPaid' => $ctx['amountPaid'],
            'balanceDue' => $ctx['balanceDue'],
            'currency' => 'NGN',
            'tenders' => array_map(fn ($t) => [
                'tenderType' => $t['tenderType'], 'amount' => $t['amount'], 'reference' => $t['reference'], 'tendered' => $t['tendered'],
            ], $ctx['tenders']),
            'changeGiven' => $change,
            'vatRegistered' => $vatRegistered,
            'vatRatePercent' => $vatRegistered ? rtrim(rtrim((string) $vat->vat_rate_percent, '0'), '.') : null,
            'vatNumber' => $vatRegistered ? $vat->vat_number : null,
            'qrPayload' => null,
            'footer' => (string) config('payments.receipt.footer', ''),
        ];

        DB::table('receipt')->insert([
            'id' => Ids::toBinary($id),
            'number' => $number,
            'organization_id' => Ids::toBinary($ctx['organizationId']),
            'site_id' => Ids::toBinary($ctx['siteId']),
            'facility_unit_id' => Ids::toBinary($ctx['facilityId']),
            'group_id' => Ids::toBinary($ctx['groupId']),
            'issued_by_staff_id' => Fmt::bin($ctx['staffId']),
            'device_id' => Fmt::bin($ctx['deviceId']),
            'amount_paid' => $ctx['amountPaid'],
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'issued_at' => $issuedAt->format('Y-m-d H:i:s.u'),
        ]);
        foreach ($payload['orderIds'] as $oid) {
            DB::table('receipt_order')->insert(['receipt_id' => Ids::toBinary($id), 'order_id' => Ids::toBinary($oid)]);
        }

        return ['id' => $id, 'number' => $number];
    }

    /** RCP-YYYYMMDD-NNNNNN, sequence per site per business day (site time zone). Atomic; serialises only on this one row. */
    private function nextNumber(string $siteId, CarbonImmutable $now): string
    {
        $tz = DB::table('site')->where('id', Ids::toBinary($siteId))->value('time_zone') ?: 'Africa/Lagos';
        $day = $now->setTimezone($tz)->format('Y-m-d');
        DB::statement(
            'INSERT INTO receipt_counter (site_id, business_date, seq_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)',
            [Ids::toBinary($siteId), $day],
        );
        $n = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return 'RCP-'.str_replace('-', '', $day).'-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> contract `Receipt` + `printLines` (pre-rendered monospace text for 80mm printers) */
    public function present(object $row, bool $duplicate = false): array
    {
        $p = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $reprints = (int) DB::table('receipt_reprint')->where('receipt_id', $row->id)->count();
        $out = ['id' => Ids::fromBinary($row->id), 'number' => $row->number] + $p + [
            'reprintCount' => $reprints,
            'duplicate' => $duplicate,
        ];
        $out['printLines'] = ReceiptRenderer::lines($out, (int) config('payments.receipt.columns', 48));

        return $out;
    }

    public function find(string $receiptId): ?object
    {
        return DB::table('receipt')->where('id', Ids::toBinary($receiptId))->first();
    }

    /** Latest receipt covering an order, or null. */
    public function latestForOrder(string $orderId): ?object
    {
        return DB::table('receipt as r')->join('receipt_order as ro', 'ro.receipt_id', '=', 'r.id')
            ->where('ro.order_id', Ids::toBinary($orderId))->orderByDesc('r.id')->select('r.*')->first();
    }

    /** Count a reprint (append-only) and audit it. Caller has already checked `receipt.reprint`. */
    public function recordReprint(object $row): void
    {
        $staffId = RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
        DB::transaction(function () use ($row, $staffId): void {
            DB::table('receipt_reprint')->insert([
                'id' => Ids::toBinary(Ids::uuid7()), 'receipt_id' => $row->id, 'staff_id' => Ids::toBinary($staffId),
                'device_id' => Fmt::bin(RequestContext::deviceId()),
            ]);
            Audit::record('receipt.reprint', 'Receipt', Ids::fromBinary($row->id), new: ['number' => $row->number], facilityUnitId: Ids::fromBinary($row->facility_unit_id));
        });
    }
}
