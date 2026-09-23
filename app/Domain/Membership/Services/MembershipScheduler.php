<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\Membership;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Time-driven membership transitions. All three steps are idempotent and safe to run concurrently and on both nodes:
 * candidates are selected by cheap indexed queries, each is moved through MembershipLifecycle::transition() with an
 * `expectFrom` guard under a row lock, so a second runner sees the new state and does nothing.
 *
 *   1. pendingRenewal: ACTIVE, term ends within the plan's renewal_notice_days  -> PENDING_RENEWAL (still usable; prompts renewal)
 *   2. grace:          term ended and the term has grace_period_days             -> stays/enters PENDING_RENEWAL with grace_until (usable in grace)
 *   3. expire:         term (and grace) over                                      -> EXPIRED
 * MembershipValidator re-checks the dates itself, so a late scheduler never admits an expired member.
 */
class MembershipScheduler
{
    public function __construct(private readonly MembershipLifecycle $lifecycle, private readonly int $batch = 500) {}

    /** @return array{pendingRenewal: int, grace: int, expired: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return ['pendingRenewal' => $this->pendingRenewal($now), 'grace' => $this->grace($now), 'expired' => $this->expire($now)];
    }

    public function pendingRenewal(CarbonImmutable $now): int
    {
        $n = 0;
        foreach ($this->candidates(
            'm.status = \'ACTIVE\' AND m.valid_until >= ? AND m.valid_until < DATE_ADD(?, INTERVAL p.renewal_notice_days DAY) AND p.renewal_notice_days > 0',
            [$this->ts($now), $this->ts($now)]
        ) as $id) {
            $n += (int) $this->lifecycle->transition($id, Membership::PENDING_RENEWAL, 'renewal window opened', 'SCHEDULER', expectFrom: [Membership::ACTIVE], strict: false);
        }

        return $n;
    }

    public function grace(CarbonImmutable $now): int
    {
        $n = 0;
        $rows = DB::select(
            'SELECT m.id, m.status, m.valid_until, m.grace_period_days FROM membership m
              WHERE m.status IN (\'ACTIVE\',\'PENDING_RENEWAL\') AND m.valid_until < ? AND m.grace_period_days > 0 AND m.grace_until IS NULL
              ORDER BY m.id LIMIT '.$this->batch,
            [$this->ts($now)]
        );
        foreach ($rows as $r) {
            $id = Ids::fromBinary($r->id);
            $graceUntil = CarbonImmutable::parse($r->valid_until, 'UTC')->addDays((int) $r->grace_period_days);
            if ($graceUntil < $now) {
                continue; // grace already over: expire() handles it
            }
            $set = ['grace_until' => $graceUntil->format('Y-m-d H:i:s.u')];
            if ($r->status === Membership::ACTIVE) {
                $n += (int) $this->lifecycle->transition($id, Membership::PENDING_RENEWAL, 'term ended, grace period started', 'SCHEDULER', expectFrom: [Membership::ACTIVE], set: $set, strict: false);
            } else {
                $n += DB::update('UPDATE membership SET grace_until = ?, row_version = row_version + 1 WHERE id = ? AND status = \'PENDING_RENEWAL\' AND grace_until IS NULL', [$set['grace_until'], $r->id]);
            }
        }

        return $n;
    }

    public function expire(CarbonImmutable $now): int
    {
        $n = 0;
        foreach ($this->candidates(
            'm.status IN (\'ACTIVE\',\'PENDING_RENEWAL\') AND ((m.grace_until IS NULL AND m.valid_until < ? AND m.grace_period_days = 0) OR m.grace_until < ?)',
            [$this->ts($now), $this->ts($now)]
        ) as $id) {
            $n += (int) $this->lifecycle->transition($id, Membership::EXPIRED, 'term ended', 'SCHEDULER', expectFrom: [Membership::ACTIVE, Membership::PENDING_RENEWAL], strict: false);
        }

        return $n;
    }

    /** @return list<string> */
    private function candidates(string $where, array $bindings): array
    {
        $rows = DB::select("SELECT m.id FROM membership m JOIN membership_plan p ON p.id = m.plan_id WHERE {$where} ORDER BY m.id LIMIT {$this->batch}", $bindings);

        return array_map(fn ($r) => Ids::fromBinary($r->id), $rows);
    }

    private function ts(CarbonImmutable $t): string
    {
        return $t->format('Y-m-d H:i:s.u');
    }
}
