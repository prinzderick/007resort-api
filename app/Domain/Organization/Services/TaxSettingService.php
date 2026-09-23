<?php

namespace App\Domain\Organization\Services;

use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0011: VAT/tax status is an admin-settable ORGANIZATION setting (default: not registered, 7.5%). Receipts and
 * pricing read it at render/compute time via get(); changing it is an audited configuration change.
 */
class TaxSettingService
{
    public const DEFAULT_RATE = '7.5000';

    /** @return array{vatEnabled: bool, vatRatePercent: string, pricesTaxInclusive: bool, vatNumber: ?string, rowVersion: int} */
    public function get(string $organizationId): array
    {
        $row = DB::table('organization_tax_setting')->where('organization_id', Ids::toBinary($organizationId))->first();

        return $this->present($row);
    }

    /**
     * @param  array<string, mixed>  $changes  vatEnabled?, vatRatePercent?, pricesTaxInclusive?, vatNumber?
     * @param  int  $expectedVersion  row version the client read (0 = no row yet)
     * @return array{vatEnabled: bool, vatRatePercent: string, pricesTaxInclusive: bool, vatNumber: ?string, rowVersion: int}
     */
    public function update(string $organizationId, array $changes, int $expectedVersion): array
    {
        $bin = Ids::toBinary($organizationId);

        return DB::transaction(function () use ($organizationId, $bin, $changes, $expectedVersion): array {
            $row = DB::table('organization_tax_setting')->where('organization_id', $bin)->lockForUpdate()->first();
            $current = (int) ($row->row_version ?? 0);
            if ($current !== $expectedVersion) {
                throw ApiProblem::concurrencyConflict('The tax setting was modified since you read it.', 412);
            }
            $old = $this->present($row);

            $values = [
                'vat_registered' => (int) ($changes['vatEnabled'] ?? $old['vatEnabled']),
                'default_vat_rate' => Money::normalize($changes['vatRatePercent'] ?? $old['vatRatePercent']),
                'prices_tax_inclusive' => (int) ($changes['pricesTaxInclusive'] ?? $old['pricesTaxInclusive']),
                'tin' => array_key_exists('vatNumber', $changes) ? ($changes['vatNumber'] ?: null) : $old['vatNumber'],
            ];
            if ($values['vat_registered'] && empty($values['tin'])) {
                throw ApiProblem::unprocessable('validation_failed', 'A VAT number (TIN) is required when VAT is enabled.', ['vatNumber' => ['Required when vatEnabled is true.']]);
            }

            try {
                if ($row === null) {
                    DB::table('organization_tax_setting')->insert(['organization_id' => $bin, 'row_version' => 1] + $values);
                } else {
                    DB::table('organization_tax_setting')->where('organization_id', $bin)->update($values + ['row_version' => $current + 1]);
                }
            } catch (QueryException $e) { // two first-time writers raced on the PK
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw ApiProblem::concurrencyConflict('The tax setting was modified since you read it.', 412);
                }
                throw $e;
            }

            $new = $this->get($organizationId);
            Audit::record('tax_setting.update', 'OrganizationTaxSetting', $organizationId, old: $old, new: $new, organizationId: $organizationId);
            Outbox::record('ConfigurationUpdated', 'OrganizationTaxSetting', $organizationId, ['domain' => 'tax_setting', 'changed' => array_keys($changes)] + $new, $new['rowVersion'], organizationId: $organizationId);

            return $new;
        });
    }

    /** @return array{vatEnabled: bool, vatRatePercent: string, pricesTaxInclusive: bool, vatNumber: ?string, rowVersion: int} */
    private function present(?object $row): array
    {
        return [
            'vatEnabled' => (bool) ($row->vat_registered ?? false),
            // contract example "7.5": trim trailing zeros for display, keep exact decimal string
            'vatRatePercent' => $this->trim($row->default_vat_rate ?? self::DEFAULT_RATE),
            'pricesTaxInclusive' => (bool) ($row->prices_tax_inclusive ?? false),
            'vatNumber' => $row->tin ?? null,
            'rowVersion' => (int) ($row->row_version ?? 0),
        ];
    }

    private function trim(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') ?: '0' : $rate;
    }
}
