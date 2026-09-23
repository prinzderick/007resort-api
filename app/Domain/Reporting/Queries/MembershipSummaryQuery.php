<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Support\Period;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Membership roster health + visit counts per facility for a local-date range. */
class MembershipSummaryQuery
{
    /** @return array<string, mixed> */
    public function run(string $siteId, string $from, string $to, int $expiringWithinDays = 7): array
    {
        $site = Ids::toBinary($siteId);
        [$f, $t] = Period::utcBounds($siteId, $from, $to);
        $byStatus = [];
        foreach (DB::select('SELECT status, COUNT(*) c FROM membership WHERE site_id = ? GROUP BY status ORDER BY status', [$site]) as $r) {
            $byStatus[$r->status] = (int) $r->c;
        }
        $byPlan = array_map(fn ($r) => ['planId' => Ids::fromBinary($r->id), 'planName' => $r->name, 'active' => (int) $r->a, 'total' => (int) $r->c],
            DB::select("SELECT p.id, p.name, SUM(m.status IN ('ACTIVE','PENDING_RENEWAL')) a, COUNT(*) c FROM membership m JOIN membership_plan p ON p.id = m.plan_id WHERE m.site_id = ? GROUP BY p.id, p.name ORDER BY p.name", [$site]));
        $now = CarbonImmutable::now('UTC');
        $expiring = (int) DB::selectOne("SELECT COUNT(*) c FROM membership WHERE site_id = ? AND status IN ('ACTIVE','PENDING_RENEWAL') AND valid_until >= ? AND valid_until < ?",
            [$site, $now->format('Y-m-d H:i:s.u'), $now->addDays($expiringWithinDays)->format('Y-m-d H:i:s.u')])->c;
        $visits = array_map(fn ($r) => ['facilityId' => Ids::fromBinary($r->fid), 'facilityName' => $r->name, 'visits' => (int) $r->c, 'guests' => (int) $r->g],
            DB::select('SELECT u.facility_unit_id fid, fu.name, COUNT(*) c, COALESCE(SUM(u.guests),0) g FROM membership_usage u JOIN facility_unit fu ON fu.id = u.facility_unit_id
                         WHERE fu.site_id = ? AND u.used_at >= ? AND u.used_at < ? GROUP BY u.facility_unit_id, fu.name ORDER BY c DESC, fu.name', [$site, $f, $t]));

        return ['from' => $from, 'to' => $to, 'byStatus' => $byStatus, 'byPlan' => $byPlan, 'expiringWithinDays' => $expiringWithinDays, 'expiring' => $expiring, 'visitsByFacility' => $visits];
    }
}
