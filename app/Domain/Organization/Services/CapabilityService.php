<?php

namespace App\Domain\Organization\Services;

use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Facility behaviour is DATA (ADR-0008, architecture/05): business code asks "does this facility have capability X and
 * what does its rule say?" — never "which named facility is this?".
 *
 *   $caps = app(CapabilityService::class);
 *   $caps->has($facilityId, 'OPEN_TAB');                       // bool
 *   $caps->requireCapability($facilityId, 'KITCHEN_ROUTING');  // throws 422 capability_disabled
 *   $caps->rules($facilityId)['approvalThresholdAmount'];      // typed effective operating rules (camelCase)
 */
class CapabilityService
{
    /** rule_key (snake_case, stored in operating_rule) => type. Unknown keys are returned as camelCase strings/ints. */
    private const RULE_TYPES = [
        'approval_threshold_amount' => 'money',
        'require_approval_for' => 'list',
        'allow_open_tabs' => 'bool',
        'require_cash_session' => 'bool',
        'allow_offline_orders' => 'bool',
        'allow_offline_payments' => 'payments',
        'hold_ttl_seconds' => 'int',
    ];

    /** @return list<string> enabled capability codes, sorted */
    public function capabilities(string $facilityId): array
    {
        return DB::table('facility_capability')->where('facility_unit_id', Ids::toBinary($facilityId))->where('is_enabled', 1)
            ->orderBy('capability_code')->pluck('capability_code')->all();
    }

    /** @param  list<string>  $facilityIds  @return array<string, list<string>> facilityId => capability codes */
    public function capabilitiesFor(array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }
        $out = array_fill_keys($facilityIds, []);
        foreach (DB::table('facility_capability')->whereIn('facility_unit_id', array_map(Ids::toBinary(...), $facilityIds))
            ->where('is_enabled', 1)->orderBy('capability_code')->get(['facility_unit_id', 'capability_code']) as $r) {
            $out[Ids::fromBinary($r->facility_unit_id)][] = $r->capability_code;
        }

        return $out;
    }

    public function has(string $facilityId, string $capability): bool
    {
        return DB::table('facility_capability')->where('facility_unit_id', Ids::toBinary($facilityId))
            ->where('capability_code', $capability)->where('is_enabled', 1)->exists();
    }

    public function requireCapability(string $facilityId, string $capability): void
    {
        if (! $this->has($facilityId, $capability)) {
            throw ApiProblem::unprocessable('capability_disabled', "Capability {$capability} is not enabled at this facility.", [
                'facilityId' => ["Capability {$capability} is not enabled."],
            ]);
        }
    }

    /**
     * Effective operating rules (camelCase, typed) merged across the facility's enabled capabilities, with defaults derived from
     * capabilities, plus the organization VAT setting.
     *
     * @return array<string, mixed>
     */
    public function rules(string $facilityId): array
    {
        $caps = $this->capabilities($facilityId);
        $has = fn (string $c) => in_array($c, $caps, true);

        $stored = [];
        foreach (DB::table('operating_rule as r')
            ->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')
            ->where('c.facility_unit_id', Ids::toBinary($facilityId))->where('c.is_enabled', 1)
            ->orderBy('c.capability_code')->orderBy('r.rule_key')->get(['r.rule_key', 'r.rule_value']) as $r) {
            $stored[$r->rule_key] = $r->rule_value;
        }

        $rules = [
            'approvalThresholdAmount' => '0.0000',
            'requireApprovalFor' => [],
            'allowOpenTabs' => $has('OPEN_TAB'),
            'requireCashSession' => $has('PAYMENT_ACCEPTANCE'),
            'allowOfflineOrders' => $has('POS') || $has('TABLE_SERVICE'),
            'allowOfflinePayments' => $has('PAYMENT_ACCEPTANCE') ? 'CASH_ONLY' : 'NONE',
            'holdTtlSeconds' => 900,
        ];
        foreach ($stored as $key => $value) {
            [$name, $typed] = $this->typed($key, $value);
            $rules[$name] = $typed;
        }

        $org = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->value('organization_id');
        $tax = $org ? app(TaxSettingService::class)->get(Ids::fromBinary($org)) : ['vatEnabled' => false, 'vatRatePercent' => '7.5'];
        $rules['vatEnabled'] = $tax['vatEnabled'];
        $rules['vatRatePercent'] = $tax['vatRatePercent'];

        return $rules;
    }

    /** @return array{capabilities: list<string>, operatingRules: array<string, mixed>, version: int} */
    public function effective(string $facilityId): array
    {
        return [
            'capabilities' => $this->capabilities($facilityId),
            'operatingRules' => $this->rules($facilityId),
            'version' => (int) DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->value('row_version'),
        ];
    }

    /** @return array{0: string, 1: mixed} camelCase name and typed value */
    private function typed(string $key, string $value): array
    {
        $name = lcfirst(str_replace('_', '', ucwords($key, '_')));

        return [$name, match (self::RULE_TYPES[$key] ?? null) {
            'money' => Money::normalize($value),
            'list' => array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== '')),
            'bool' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'payments' => in_array($value, ['NONE', 'CASH_ONLY', 'ALL'], true) ? $value : 'NONE',
            'int' => (int) $value,
            default => preg_match('/(_minutes|_seconds|_days|_count)$/', $key) && ctype_digit($value) ? (int) $value : $value,
        }];
    }
}
