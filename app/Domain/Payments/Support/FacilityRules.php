<?php

namespace App\Domain\Payments\Support;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Reads the Payments-relevant operating rules of a facility (architecture/05: `operating_rule` key/value rows under the
 * facility's capabilities). Nothing here branches on a facility's name/code - behaviour is data.
 *
 * Rule keys (documented in docs/PAYMENTS.md):
 *   payment_timing            PAY_FIRST | PAY_ON_EXIT | PAY_AFTER_SERVICE | ANY (default ANY)
 *   require_cash_session      true|false (default true)
 *   require_approval_for      comma list, e.g. "order.void,payment.refund,payment.reversal" (default: refunds & reversals need approval)
 *   approval_threshold_amount decimal; refunds/reversals of at most this amount need no approval (default: none)
 */
final class FacilityRules
{
    public const TIMING_ANY = 'ANY';

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /** @return array<string, string> rule_key => rule_value */
    public function all(string $facilityId): array
    {
        if (isset($this->cache[$facilityId])) {
            return $this->cache[$facilityId];
        }
        $rows = DB::table('operating_rule as r')
            ->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')
            ->where('c.facility_unit_id', Ids::toBinary($facilityId))
            ->orderBy('r.updated_at')
            ->get(['r.rule_key', 'r.rule_value']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->rule_key] = (string) $r->rule_value;
        }

        return $this->cache[$facilityId] = $out;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public function paymentTiming(string $facilityId): string
    {
        $v = strtoupper($this->all($facilityId)['payment_timing'] ?? self::TIMING_ANY);

        return in_array($v, ['PAY_FIRST', 'PAY_ON_EXIT', 'PAY_AFTER_SERVICE', 'ANY'], true) ? $v : self::TIMING_ANY;
    }

    public function requireCashSession(string $facilityId): bool
    {
        return ! in_array(strtolower($this->all($facilityId)['require_cash_session'] ?? 'true'), ['false', '0', 'no', 'off'], true);
    }

    /** Does the facility rule demand supervisor approval for this sensitive payment action ("payment.refund" / "payment.reversal")? */
    public function approvalRequired(string $facilityId, string $action, string $amount): bool
    {
        $rules = $this->all($facilityId);
        if (isset($rules['require_approval_for'])) {
            $list = array_map('trim', explode(',', $rules['require_approval_for']));
            if (! in_array($action, $list, true)) {
                return false;
            }
        }
        if (isset($rules['approval_threshold_amount']) && preg_match('/^\d+(\.\d+)?$/', $rules['approval_threshold_amount'])) {
            return bccomp($amount, $rules['approval_threshold_amount'], 4) > 0;
        }

        return true;
    }
}
