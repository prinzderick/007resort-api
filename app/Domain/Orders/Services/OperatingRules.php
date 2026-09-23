<?php

namespace App\Domain\Orders\Services;

use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Facility operating rules relevant to orders (architecture/05: operating_rule rows scoped to a facility_capability).
 *
 *   payment_timing            PAY_FIRST | PAY_AFTER_SERVICE (default; alias PAY_BEFORE_LEAVING) | OPEN_TAB (aliases PAY_ON_EXIT, PAY_AT_RECEPTION)
 *   approval_threshold_amount discount amount (NGN) a caller WITHOUT the approve permission may apply directly (default 0)
 *   require_approval_for      comma list of action codes that always need approval for non-approvers (e.g. order.void)
 * Capability OPEN_TAB enabled => open tabs allowed.
 */
final class OperatingRules
{
    public const PAY_FIRST = 'PAY_FIRST';

    public const PAY_AFTER_SERVICE = 'PAY_AFTER_SERVICE';

    public const OPEN_TAB = 'OPEN_TAB';

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /** @return array{paymentTiming: string, approvalThresholdAmount: string, requireApprovalFor: list<string>, allowOpenTabs: bool, capabilities: list<string>} */
    public function forFacility(string $facilityId): array
    {
        if (isset($this->cache[$facilityId])) {
            return $this->cache[$facilityId];
        }
        $bin = Ids::toBinary($facilityId);
        $caps = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('is_enabled', 1)->get(['id', 'capability_code']);
        $rules = $caps->isEmpty() ? collect() : DB::table('operating_rule')->whereIn('facility_capability_id', $caps->pluck('id')->all())->get(['rule_key', 'rule_value'])->pluck('rule_value', 'rule_key');
        $timing = strtoupper((string) ($rules['payment_timing'] ?? self::PAY_AFTER_SERVICE));
        // tab-style timings (orders accumulate on the table's tab, settled once) vs pay-after-service vs pay-first
        $timing = match ($timing) {
            'PAY_ON_EXIT', 'PAY_AT_RECEPTION' => self::OPEN_TAB,
            'PAY_BEFORE_LEAVING' => self::PAY_AFTER_SERVICE,
            default => $timing,
        };
        $codes = $caps->pluck('capability_code')->all();

        return $this->cache[$facilityId] = [
            'paymentTiming' => $timing,
            'approvalThresholdAmount' => Money::isValid($rules['approval_threshold_amount'] ?? null) ? Money::normalize($rules['approval_threshold_amount']) : '0.0000',
            'requireApprovalFor' => array_values(array_filter(array_map('trim', explode(',', (string) ($rules['require_approval_for'] ?? ''))))),
            'allowOpenTabs' => in_array('OPEN_TAB', $codes, true) || $timing === self::OPEN_TAB,
            'capabilities' => $codes,
        ];
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
