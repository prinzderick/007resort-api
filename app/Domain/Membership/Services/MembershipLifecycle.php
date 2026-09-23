<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\MembershipStatusHistory;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place a membership's status changes. Every transition is:
 *  - validated against Membership::TRANSITIONS,
 *  - applied under a row lock (SELECT ... FOR UPDATE) with an `expectFrom` guard, so it is idempotent and safe when the
 *    scheduler runs on both nodes or twice concurrently: the loser sees the new status and returns `false` without effect,
 *  - recorded in membership_status_history + audit + outbox (`MembershipStatusChanged`) in the same transaction.
 */
class MembershipLifecycle
{
    /**
     * @param  list<string>|null  $expectFrom  only transition if the CURRENT status is one of these (null = any allowed by the map)
     * @param  array<string, mixed>  $set  extra columns to update atomically with the status
     * @return bool true if this call performed the transition, false if it was already in (or past) the target state
     */
    public function transition(
        string $membershipId,
        string $to,
        string $reason,
        string $source = 'API',
        ?string $actorStaffId = null,
        ?array $expectFrom = null,
        array $set = [],
        bool $strict = true,
    ): bool {
        return DB::transaction(function () use ($membershipId, $to, $reason, $source, $actorStaffId, $expectFrom, $set, $strict): bool {
            $row = DB::selectOne('SELECT id, organization_id, site_id, status, row_version FROM membership WHERE id = ? FOR UPDATE', [Ids::toBinary($membershipId)]);
            if ($row === null) {
                throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
            }
            $from = $row->status;
            if ($expectFrom !== null && ! in_array($from, $expectFrom, true)) {
                return false; // someone else already moved it (idempotent no-op)
            }
            if ($from === $to && $to !== Membership::ACTIVE) {
                return false;
            }
            if (! in_array($to, Membership::TRANSITIONS[$from] ?? [], true)) {
                if (! $strict) {
                    return false;
                }
                throw ApiProblem::conflict('invalid_membership_transition', "A {$from} membership cannot become {$to}.", ['from' => $from, 'to' => $to]);
            }

            $version = (int) $row->row_version + 1;
            DB::table('membership')->where('id', $row->id)->update($set + ['status' => $to, 'row_version' => $version]);
            DB::table('membership_status_history')->insert([
                'id' => Ids::toBinary(Ids::uuid7()),
                'membership_id' => $row->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => mb_substr($reason, 0, 255),
                'source' => $source,
                'actor_staff_id' => $actorStaffId === null ? null : Ids::toBinary($actorStaffId),
                'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            $org = Ids::fromBinary($row->organization_id);
            $site = Ids::fromBinary($row->site_id);
            Audit::record('membership.status.'.strtolower($to), 'Membership', $membershipId, ['status' => $from], ['status' => $to, 'reason' => $reason, 'source' => $source],
                organizationId: $org, siteId: $site, actorStaffId: $actorStaffId);
            Outbox::record('MembershipStatusChanged', 'Membership', $membershipId,
                ['membershipId' => $membershipId, 'from' => $from, 'to' => $to, 'reason' => $reason, 'source' => $source],
                entityVersion: $version, organizationId: $org, siteId: $site);

            return true;
        });
    }

    /** History rows, newest first. */
    public function history(string $membershipId): array
    {
        return MembershipStatusHistory::query()->where('membership_id', $membershipId)->orderByDesc('id')->get()
            ->map(fn (MembershipStatusHistory $h) => $h->toApi())->all();
    }
}
