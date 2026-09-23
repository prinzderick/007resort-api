<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Support\Freshness;
use App\Domain\Reporting\Support\Period;
use App\Domain\Reporting\Support\SourceCatalog;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Tenancy\FacilityTree;
use Illuminate\Support\Facades\DB;

/**
 * Facility daily summary (contract FacilityDailySummary). Covers the facility AND its descendants (Restaurant includes its
 * counters), business date = site-local calendar date.
 *
 *   grossSales = SUM(order.subtotal)            (before discounts)     discounts = SUM(order.discount_total)     tax = SUM(order.tax_total)
 *   total      = SUM(order.total)               (after discounts, tax included when tax-inclusive)
 *   netSales   = total - refunds - reversals    ("net takings")
 * Orders count when SETTLED with settled_at inside the day. Tender rows/refunds come from Payments (captured_at / created_at in the day).
 * Sources that are not deployed yet (payment, refund, line_void, redemption) contribute zeros and `availability.<source> = false`.
 */
class FacilityDailySummaryQuery
{
    /** @return array<string, mixed>|null null when the facility does not exist */
    public function run(string $facilityId, string $date): ?array
    {
        $siteId = Freshness::siteOfFacility($facilityId);
        if ($siteId === null) {
            return null;
        }
        [$from, $to] = Period::utcBounds($siteId, $date, $date);
        $ids = Period::bins(FacilityTree::selfAndDescendants($facilityId));
        $in = Period::marks($ids);
        $zero = Money::zero()->amount;

        $avail = SourceCatalog::availability([
            'orders' => ['order', 'facility_unit_id', 'status', 'settled_at', 'subtotal', 'discount_total', 'tax_total', 'total'],
            'payments' => ['payment', 'facility_unit_id', 'tender_type', 'status', 'amount', 'captured_at'],
            'refunds' => ['refund', 'payment_id', 'amount', 'created_at'],
            'reversals' => ['reversal', 'payment_id', 'amount', 'created_at'],
            'voids' => ['line_void', 'order_id', 'amount', 'created_at'],
            'tickets' => ['redemption'],
        ]);

        $orders = 0;
        $gross = $disc = $tax = $total = $zero;
        if ($avail['orders']) {
            $r = DB::selectOne("SELECT COUNT(*) c, COALESCE(SUM(subtotal),0) g, COALESCE(SUM(discount_total),0) d, COALESCE(SUM(tax_total),0) t, COALESCE(SUM(total),0) tot
                                  FROM `order` WHERE status = 'SETTLED' AND facility_unit_id IN ($in) AND settled_at >= ? AND settled_at < ?", [...$ids, $from, $to]);
            [$orders, $gross, $disc, $tax, $total] = [(int) $r->c, Money::normalize($r->g), Money::normalize($r->d), Money::normalize($r->t), Money::normalize($r->tot)];
        }

        $refunds = $reversals = $zero;
        if ($avail['refunds'] && $avail['payments']) {
            $refunds = Money::normalize(DB::selectOne("SELECT COALESCE(SUM(r.amount),0) a FROM refund r JOIN payment p ON p.id = r.payment_id
                                                       WHERE p.facility_unit_id IN ($in) AND r.created_at >= ? AND r.created_at < ?", [...$ids, $from, $to])->a);
        }
        if ($avail['reversals'] && $avail['payments']) {
            $reversals = Money::normalize(DB::selectOne("SELECT COALESCE(SUM(r.amount),0) a FROM reversal r JOIN payment p ON p.id = r.payment_id
                                                         WHERE p.facility_unit_id IN ($in) AND r.created_at >= ? AND r.created_at < ?", [...$ids, $from, $to])->a);
        }

        $voidCount = 0;
        $voidAmount = $zero;
        if ($avail['voids'] && $avail['orders']) {
            $v = DB::selectOne("SELECT COUNT(*) c, COALESCE(SUM(v.amount),0) a FROM line_void v JOIN `order` o ON o.id = v.order_id
                                 WHERE o.facility_unit_id IN ($in) AND v.created_at >= ? AND v.created_at < ?", [...$ids, $from, $to]);
            [$voidCount, $voidAmount] = [(int) $v->c, Money::normalize($v->a)];
        }

        $byTender = [];
        if ($avail['payments']) {
            foreach (DB::select("SELECT tender_type, COUNT(*) c, COALESCE(SUM(amount),0) a FROM payment
                                  WHERE facility_unit_id IN ($in) AND status IN ('CAPTURED','PARTIALLY_REFUNDED','REFUNDED','REVERSED') AND captured_at >= ? AND captured_at < ?
                                  GROUP BY tender_type ORDER BY tender_type", [...$ids, $from, $to]) as $t) {
                $byTender[] = ['tenderType' => $t->tender_type, 'amount' => Money::normalize($t->a), 'count' => (int) $t->c];
            }
        }

        $top = [];
        if ($avail['orders']) {
            foreach (DB::select("SELECT l.product_id, MAX(l.product_name) n, SUM(l.quantity) q, SUM(l.line_total) rev
                                   FROM order_line l JOIN `order` o ON o.id = l.order_id
                                  WHERE o.status = 'SETTLED' AND o.facility_unit_id IN ($in) AND o.settled_at >= ? AND o.settled_at < ? AND l.status NOT IN ('VOIDED','REMOVED')
                                  GROUP BY l.product_id ORDER BY rev DESC, n LIMIT 10", [...$ids, $from, $to]) as $p) {
                $top[] = ['productId' => Ids::fromBinary($p->product_id), 'name' => $p->n, 'quantity' => (int) $p->q, 'revenue' => Money::normalize($p->rev)];
            }
        }

        // Ticketing owns `redemption`; counted once its schema (facility + timestamp columns) is final. Until then 0 + availability.tickets.
        $tickets = 0;

        return [
            'facilityId' => $facilityId, 'date' => $date, 'orders' => $orders,
            'grossSales' => $gross, 'discounts' => $disc, 'tax' => $tax, 'total' => $total,
            'netSales' => Money::of($total)->sub(Money::of($refunds))->sub(Money::of($reversals))->amount,
            'refunds' => $refunds, 'reversals' => $reversals,
            'voids' => ['count' => $voidCount, 'amount' => $voidAmount],
            'byTender' => $byTender, 'topProducts' => $top, 'ticketsRedeemed' => $tickets, 'currency' => 'NGN',
            'availability' => $avail,
        ];
    }
}
