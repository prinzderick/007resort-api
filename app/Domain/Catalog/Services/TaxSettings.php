<?php

namespace App\Domain\Catalog\Services;

use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Organization VAT setting (ADR-0011): admin-editable, default OFF, 7.5 % when switched on. */
final class TaxSettings
{
    public const DEFAULT_RATE = '7.5000';

    /** @return array{vatEnabled: bool, vatRatePercent: string, pricesTaxInclusive: bool, vatNumber: ?string, rowVersion: int} */
    public function get(string $organizationId): array
    {
        $r = DB::table('organization_tax_setting')->where('organization_id', Ids::toBinary($organizationId))->first();
        if ($r === null) {
            return ['vatEnabled' => false, 'vatRatePercent' => '7.5', 'pricesTaxInclusive' => true, 'vatNumber' => null, 'rowVersion' => 0];
        }

        return [
            'vatEnabled' => (bool) $r->vat_enabled,
            'vatRatePercent' => rtrim(rtrim($r->vat_rate_percent, '0'), '.') ?: '0',
            'pricesTaxInclusive' => (bool) $r->prices_tax_inclusive,
            'vatNumber' => $r->vat_number,
            'rowVersion' => (int) $r->row_version,
        ];
    }

    /**
     * @param  array{vatEnabled?: bool, vatRatePercent?: string, pricesTaxInclusive?: bool, vatNumber?: ?string}  $in
     * @return array<string, mixed>
     */
    public function update(string $organizationId, array $in, ?int $expectedVersion): array
    {
        return DB::transaction(function () use ($organizationId, $in, $expectedVersion) {
            $ob = Ids::toBinary($organizationId);
            DB::table('organization_tax_setting')->insertOrIgnore(['organization_id' => $ob]);
            $row = DB::table('organization_tax_setting')->where('organization_id', $ob)->lockForUpdate()->first();
            // a freshly inserted default row has version 1; the "virtual" pre-insert version is 0
            $current = (int) $row->row_version;
            if ($expectedVersion !== null && $expectedVersion !== $current && ! ($expectedVersion === 0 && $current === 1 && $row->updated_at === $row->created_at)) {
                throw new ApiProblem(412, 'concurrency_conflict', 'The tax setting was changed by someone else.', 'Precondition failed');
            }
            $old = $this->get($organizationId);
            $upd = [];
            if (array_key_exists('vatEnabled', $in)) {
                $upd['vat_enabled'] = $in['vatEnabled'] ? 1 : 0;
            }
            if (array_key_exists('vatRatePercent', $in)) {
                if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $in['vatRatePercent']) || bccomp($in['vatRatePercent'], '100', 4) > 0) {
                    throw ApiProblem::unprocessable('validation_failed', 'vatRatePercent must be a decimal string between 0 and 100.');
                }
                $upd['vat_rate_percent'] = $in['vatRatePercent'];
            }
            if (array_key_exists('pricesTaxInclusive', $in)) {
                $upd['prices_tax_inclusive'] = $in['pricesTaxInclusive'] ? 1 : 0;
            }
            if (array_key_exists('vatNumber', $in)) {
                $upd['vat_number'] = $in['vatNumber'];
            }
            $upd['row_version'] = $current + 1;
            $upd['updated_at'] = Fmt::now();
            DB::table('organization_tax_setting')->where('organization_id', $ob)->update($upd);
            $new = $this->get($organizationId);
            Audit::record('config.tax.update', 'OrganizationTaxSetting', $organizationId, old: $old, new: $new);

            return $new;
        });
    }
}
