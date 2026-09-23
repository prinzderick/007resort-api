<?php

namespace App\Domain\Reporting\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read-only SQL views for the admin-web's view-restricted DB credential (architecture/04 §4, ADR-0007). A view is created ONLY when every
 * table/column it reads exists, so the same code runs on a deployment that has not got Payments/Orders yet; re-run
 * `php artisan r007:reporting:views` after adding modules (the migration also calls it). Business dates use a FIXED UTC offset
 * (config reporting.view_utc_offset, Africa/Lagos = +01:00, no DST) because MySQL time-zone tables are commonly not loaded.
 * The API endpoints do NOT depend on these views (they use site-time-zone ranges in PHP); views are for external read-only tools.
 */
final class ReportingViews
{
    /** @return array<string, string> view name => 'created'|'skipped: ...'|'failed: ...' */
    public static function install(): array
    {
        $off = (string) config('reporting.view_utc_offset', '+01:00');
        if (! preg_match('/^[+-]\d{2}:\d{2}$/', $off)) {
            $off = '+01:00';
        }
        $local = fn (string $col) => "DATE(CONVERT_TZ({$col}, '+00:00', '{$off}'))";

        $views = [
            'v_facility_daily_summary' => [
                'needs' => [['order', 'facility_unit_id', 'status', 'settled_at', 'subtotal', 'discount_total', 'tax_total', 'total']],
                'sql' => "SELECT o.facility_unit_id, {$local('o.settled_at')} AS business_date, COUNT(*) AS orders, SUM(o.subtotal) AS gross_sales,
                                 SUM(o.discount_total) AS discounts, SUM(o.tax_total) AS tax, SUM(o.total) AS total
                            FROM `order` o WHERE o.status = 'SETTLED' AND o.settled_at IS NOT NULL GROUP BY o.facility_unit_id, business_date",
            ],
            'v_payments_by_tender_daily' => [
                'needs' => [['payment', 'facility_unit_id', 'tender_type', 'status', 'amount', 'refunded_amount', 'captured_at']],
                'sql' => "SELECT p.facility_unit_id, {$local('p.captured_at')} AS business_date, p.tender_type, COUNT(*) AS payments,
                                 SUM(p.amount) AS captured, SUM(p.refunded_amount) AS refunded, SUM(p.amount - p.refunded_amount) AS net
                            FROM payment p WHERE p.status IN ('CAPTURED','PARTIALLY_REFUNDED','REFUNDED','REVERSED') AND p.captured_at IS NOT NULL
                           GROUP BY p.facility_unit_id, business_date, p.tender_type",
            ],
            'v_cashier_shift_report' => [
                'needs' => [['cash_session', 'opening_float', 'expected_cash', 'counted_cash', 'variance', 'status', 'staff_id', 'facility_unit_id', 'opened_at']],
                'sql' => 'SELECT cs.id AS cash_session_id, cs.facility_unit_id, cs.staff_id, cs.status, cs.opened_at, cs.closed_at, cs.opening_float, cs.expected_cash, cs.counted_cash, cs.variance
                            FROM cash_session cs',
            ],
            'v_attendance_day_summary' => [
                'needs' => [['attendance_day', 'staff_id', 'work_date', 'minutes_worked', 'status'], ['staff', 'staff_number']],
                'sql' => 'SELECT d.site_id, d.work_date, d.staff_id, s.staff_number, s.first_name, s.last_name, d.clock_in, d.clock_out, d.minutes_worked, d.punch_count, d.source, d.status
                            FROM attendance_day d JOIN staff s ON s.id = d.staff_id',
            ],
            'v_membership_status_summary' => [
                'needs' => [['membership', 'status', 'plan_id', 'valid_until'], ['membership_plan', 'name']],
                'sql' => 'SELECT m.site_id, p.name AS plan_name, m.status, COUNT(*) AS memberships, MIN(m.valid_until) AS next_expiry
                            FROM membership m JOIN membership_plan p ON p.id = m.plan_id GROUP BY m.site_id, p.name, m.status',
            ],
        ];

        $result = [];
        foreach ($views as $name => $v) {
            foreach ($v['needs'] as $need) {
                if (! SourceCatalog::has(...$need)) {
                    $result[$name] = 'skipped: needs '.$need[0];
                    DB::statement("DROP VIEW IF EXISTS {$name}");

                    continue 2;
                }
            }
            try {
                DB::statement("CREATE OR REPLACE VIEW {$name} AS {$v['sql']}");
                $result[$name] = 'created';
            } catch (\Throwable $e) {
                Log::warning("reporting view {$name} not created: ".$e->getMessage());
                $result[$name] = 'failed: '.$e->getMessage();
            }
        }

        return $result;
    }

    public static function flushCaches(): void
    {
        SourceCatalog::flush();
    }

    public static function dropAll(): void
    {
        foreach (['v_facility_daily_summary', 'v_payments_by_tender_daily', 'v_cashier_shift_report', 'v_attendance_day_summary', 'v_membership_status_summary'] as $v) {
            DB::statement("DROP VIEW IF EXISTS {$v}");
        }
    }
}
