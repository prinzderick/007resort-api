<?php

namespace App\Domain\Payments\Support;

use App\Domain\Orders\Services\OperatingRules;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Payments-relevant operating rules of a facility (architecture/05: `operating_rule` key/value rows under the facility's
 * capabilities). Nothing branches on a facility's name/code - behaviour is data. Timing and approval rules are read through
 * Orders' {@see OperatingRules} so both modules interpret the same keys the same way:
 *
 *   payment_timing            PAY_FIRST | PAY_AFTER_SERVICE (default) | OPEN_TAB | PAY_ON_EXIT (alias of OPEN_TAB)
 *   approval_threshold_amount refunds/reversals of at most this amount need no approval (default 0 = always needs approval)
 *   require_approval_for      comma list of action codes that ALWAYS need approval for non-approvers: `payment.refund`, `payment.reversal`
 *   require_cash_session      true|false (default true) - cash needs an OPEN cash session (Payments' own key)
 */
final class FacilityRules
{
    /** @var array<string, bool> */
    private array $cashSession = [];

    public function __construct(private readonly OperatingRules $orderRules) {}

    public function paymentTiming(string $facilityId): string
    {
        return $this->orderRules->forFacility($facilityId)['paymentTiming'];
    }

    public function requireCashSession(string $facilityId): bool
    {
        if (isset($this->cashSession[$facilityId])) {
            return $this->cashSession[$facilityId];
        }
        $v = DB::table('operating_rule as r')->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')
            ->where('c.facility_unit_id', Ids::toBinary($facilityId))->where('r.rule_key', 'require_cash_session')->orderByDesc('r.updated_at')->value('r.rule_value');

        return $this->cashSession[$facilityId] = ! in_array(strtolower((string) ($v ?? 'true')), ['false', '0', 'no', 'off'], true);
    }

    /** Does the facility rule demand supervisor approval for this sensitive action ("payment.refund" / "payment.reversal") from a non-approver? */
    public function approvalRequired(string $facilityId, string $action, string $amount): bool
    {
        $r = $this->orderRules->forFacility($facilityId);
        if (in_array($action, $r['requireApprovalFor'], true)) {
            return true;
        }

        return bccomp($amount, $r['approvalThresholdAmount'], 4) > 0;
    }

    public function forget(): void
    {
        $this->cashSession = [];
        $this->orderRules->flush();
    }
}
