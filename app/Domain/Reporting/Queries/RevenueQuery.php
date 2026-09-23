<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Support\Period;
use App\Domain\Reporting\Support\SourceCatalog;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Tenancy\FacilityTree;
use Illuminate\Support\Facades\DB;

/**
 * Revenue for a local-date range, cut three ways: by facility, by operating point (device binding label at order time, else device name)
 * and by payment method. Revenue = SUM(order.total) of SETTLED orders (settled_at in range); payment-method figures are captured
 * tender amounts less refunds.
 */
class RevenueQuery
{
    /** @param list<string>|null $facilityIds restrict to these facilities (already expanded to descendants); null = whole site */
    public function run(string $siteId, string $from, string $to, ?array $facilityIds): array
    {
        [$f, $t] = Period::utcBounds($siteId, $from, $to);
        $avail = SourceCatalog::availability([
            'orders' => ['order', 'facility_unit_id', 'status', 'settled_at', 'total', 'device_id'],
            'payments' => ['payment', 'facility_unit_id', 'tender_type', 'status', 'amount', 'captured_at', 'refunded_amount'],
        ]);
        $siteBin = Ids::toBinary($siteId);
        $facFilterO = $facilityIds === null ? '' : ' AND o.facility_unit_id IN ('.Period::marks($facilityIds).')';
        $facFilterP = $facilityIds === null ? '' : ' AND p.facility_unit_id IN ('.Period::marks($facilityIds).')';
        $facBins = $facilityIds === null ? [] : Period::bins($facilityIds);

        $byFacility = $byOp = $byMethod = [];
        $revenue = Money::zero()->amount;
        $orders = 0;
        if ($avail['orders']) {
            foreach (DB::select("SELECT o.facility_unit_id fid, fu.code code, fu.name name, COUNT(*) c, COALESCE(SUM(o.total),0) rev
                                   FROM `order` o JOIN facility_unit fu ON fu.id = o.facility_unit_id
                                  WHERE o.status = 'SETTLED' AND o.site_id = ? AND o.settled_at >= ? AND o.settled_at < ? $facFilterO
                                  GROUP BY o.facility_unit_id, fu.code, fu.name ORDER BY rev DESC, fu.code", [$siteBin, $f, $t, ...$facBins]) as $r) {
                $byFacility[] = ['facilityId' => Ids::fromBinary($r->fid), 'code' => $r->code, 'name' => $r->name, 'orders' => (int) $r->c, 'revenue' => Money::normalize($r->rev)];
                $revenue = Money::of($revenue)->add(Money::of($r->rev))->amount;
                $orders += (int) $r->c;
            }
            if (SourceCatalog::has('device_binding', 'operating_point_label') && SourceCatalog::has('device', 'name')) {
                foreach (DB::select("SELECT COALESCE(db.operating_point_label, d.name, 'UNASSIGNED') label, COUNT(*) c, COALESCE(SUM(o.total),0) rev
                                       FROM `order` o LEFT JOIN device d ON d.id = o.device_id
                                       LEFT JOIN device_binding db ON db.device_id = o.device_id AND db.bound_at <= o.created_at AND (db.unbound_at IS NULL OR db.unbound_at > o.created_at)
                                      WHERE o.status = 'SETTLED' AND o.site_id = ? AND o.settled_at >= ? AND o.settled_at < ? $facFilterO
                                      GROUP BY label ORDER BY rev DESC, label", [$siteBin, $f, $t, ...$facBins]) as $r) {
                    $byOp[] = ['operatingPoint' => $r->label, 'orders' => (int) $r->c, 'revenue' => Money::normalize($r->rev)];
                }
            }
        }
        if ($avail['payments']) {
            foreach (DB::select("SELECT p.tender_type, COUNT(*) c, COALESCE(SUM(p.amount),0) a, COALESCE(SUM(p.refunded_amount),0) r
                                   FROM payment p WHERE p.site_id = ? AND p.status IN ('CAPTURED','PARTIALLY_REFUNDED','REFUNDED','REVERSED') AND p.captured_at >= ? AND p.captured_at < ? $facFilterP
                                  GROUP BY p.tender_type ORDER BY p.tender_type", [$siteBin, $f, $t, ...$facBins]) as $r) {
                $byMethod[] = ['tenderType' => $r->tender_type, 'count' => (int) $r->c, 'captured' => Money::normalize($r->a), 'refunded' => Money::normalize($r->r), 'net' => Money::of($r->a)->sub(Money::of($r->r))->amount];
            }
        }

        return [
            'from' => $from, 'to' => $to, 'currency' => 'NGN', 'orders' => $orders, 'revenue' => $revenue,
            'byFacility' => $byFacility, 'byOperatingPoint' => $byOp, 'byPaymentMethod' => $byMethod, 'availability' => $avail,
        ];
    }

    /** @return list<string>|null */
    public static function expand(?string $facilityId): ?array
    {
        return $facilityId === null ? null : FacilityTree::selfAndDescendants($facilityId);
    }
}
