<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\MemberCard;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Support\CardCodec;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\FacilityTree;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Validate (and by default consume one visit of) a membership at a facility - callable from ANY facility scanner / NFC
 * reader / POS. Resolution: QR token | NFC uid | membership number.
 *
 * Visit-limit enforcement is a single conditional UPDATE
 *     UPDATE membership SET visits_used = visits_used + 1 WHERE id = ? AND status IN (...) AND (visit_limit IS NULL OR visits_used < visit_limit)
 * so N concurrent scans against a limit of N admit exactly N, however many nodes/processes race (never read-then-write in PHP).
 * Scanner retries are deduplicated by `clientRef` (unique per membership): the retry returns the original admission.
 */
class MembershipValidator
{
    public const NOT_FOUND = 'NOT_FOUND';

    public const NOT_ACTIVE = 'NOT_ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const EXPIRED = 'EXPIRED';

    public const WRONG_FACILITY = 'WRONG_FACILITY';

    public const VISIT_LIMIT_REACHED = 'VISIT_LIMIT_REACHED';

    public const GUEST_LIMIT_EXCEEDED = 'GUEST_LIMIT_EXCEEDED';

    /** @param array{qrToken?: ?string, nfcUid?: ?string, membershipNumber?: ?string, guests?: int, clientRef?: ?string, consume?: bool, deviceId?: ?string, staffId?: ?string} $in */
    public function validate(array $in, string $facilityId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $consume = $in['consume'] ?? true;
        $guests = max(0, (int) ($in['guests'] ?? 0));

        $card = $this->resolveCard($in);
        if ($card === null) {
            return $this->deny(self::NOT_FOUND);
        }
        $m = Membership::query()->with(['plan', 'customer'])->find($card->membership_id);
        if ($m === null) {
            return $this->deny(self::NOT_FOUND);
        }

        // Scanner retry: same clientRef already admitted -> return that admission untouched.
        if ($consume && ! empty($in['clientRef'])) {
            $prior = DB::table('membership_usage')->where('membership_id', Ids::toBinary($m->id))->where('client_ref', $in['clientRef'])->first();
            if ($prior !== null) {
                return $this->admit($m->refresh(), $facilityId, $prior->guests, (int) $prior->visit_number, (string) $prior->discount_percent, true, $now);
            }
        }

        if ($reason = $this->staticReason($m, $facilityId, $guests, $now)) {
            return $this->deny($reason, $m, $now);
        }
        $discount = $this->discountFor($m, $facilityId);

        if (! $consume) {
            return $this->admit($m, $facilityId, $guests, null, $discount, false, $now);
        }

        try {
            $visit = DB::transaction(function () use ($m, $facilityId, $guests, $card, $in, $discount, $now): ?int {
                $graceCutoff = $now->format('Y-m-d H:i:s.u');
                // Conditional UPDATE = the gate. Term is re-checked in SQL so a scheduler that lags cannot admit an expired member.
                $n = DB::update(
                    'UPDATE membership SET visits_used = visits_used + 1, row_version = row_version + 1
                      WHERE id = ? AND status IN (\'ACTIVE\',\'PENDING_RENEWAL\')
                        AND (visit_limit IS NULL OR visits_used < visit_limit)
                        AND (valid_until >= ? OR (grace_until IS NOT NULL AND grace_until >= ?))',
                    [Ids::toBinary($m->id), $graceCutoff, $graceCutoff]
                );
                if ($n === 0) {
                    return null;
                }
                $row = DB::selectOne('SELECT visits_used, renewal_count FROM membership WHERE id = ?', [Ids::toBinary($m->id)]);
                $visit = (int) $row->visits_used;
                $usageId = Ids::uuid7();
                DB::table('membership_usage')->insert([
                    'id' => Ids::toBinary($usageId), 'membership_id' => Ids::toBinary($m->id), 'facility_unit_id' => Ids::toBinary($facilityId),
                    'used_at' => $now->format('Y-m-d H:i:s.u'), 'guests' => $guests, 'term' => (int) $row->renewal_count, 'visit_number' => $visit,
                    'discount_percent' => $discount, 'card_id' => Ids::toBinary($card->id),
                    'device_id' => empty($in['deviceId']) ? null : Ids::toBinary($in['deviceId']),
                    'staff_id' => empty($in['staffId']) ? null : Ids::toBinary($in['staffId']),
                    'client_ref' => $in['clientRef'] ?? null,
                ]);
                Outbox::record('MembershipUsageRecorded', 'Membership', $m->id, [
                    'membershipId' => $m->id, 'usageId' => $usageId, 'facilityId' => $facilityId, 'usedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                    'guests' => $guests, 'visitNumber' => $visit,
                ], entityVersion: (int) $m->row_version + 1, organizationId: $m->organization_id, siteId: $m->site_id, facilityId: $facilityId);

                return $visit;
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate clientRef: the loser's transaction (incl. its visit increment) rolled back.
            $prior = DB::table('membership_usage')->where('membership_id', Ids::toBinary($m->id))->where('client_ref', $in['clientRef'] ?? '')->first();
            if ($prior !== null) {
                return $this->admit($m->refresh(), $facilityId, $prior->guests, (int) $prior->visit_number, (string) $prior->discount_percent, true, $now);
            }
            throw new \RuntimeException('Unexpected duplicate membership usage.');
        }

        if ($visit === null) {
            $fresh = Membership::query()->with(['plan', 'customer'])->find($m->id);
            $reason = $fresh->visit_limit !== null && $fresh->visits_used >= $fresh->visit_limit && $fresh->isUsableAt($now)
                ? self::VISIT_LIMIT_REACHED
                : ($this->staticReason($fresh, $facilityId, $guests, $now) ?? self::NOT_ACTIVE);

            return $this->deny($reason, $fresh, $now);
        }

        return $this->admit($m->refresh(), $facilityId, $guests, $visit, $discount, false, $now);
    }

    private function staticReason(Membership $m, string $facilityId, int $guests, CarbonImmutable $now): ?string
    {
        if ($m->status === Membership::SUSPENDED) {
            return self::SUSPENDED;
        }
        if ($m->status === Membership::EXPIRED) {
            return self::EXPIRED;
        }
        if (! in_array($m->status, [Membership::ACTIVE, Membership::PENDING_RENEWAL], true)) {
            return self::NOT_ACTIVE;
        }
        if (! $m->isUsableAt($now)) {
            return self::EXPIRED;
        }
        if (! $this->covers($m, $facilityId)) {
            return self::WRONG_FACILITY;
        }
        if ($guests > $m->guest_allowance) {
            return self::GUEST_LIMIT_EXCEEDED;
        }
        if ($m->visit_limit !== null && $m->visits_used >= $m->visit_limit) {
            return self::VISIT_LIMIT_REACHED;
        }

        return null;
    }

    /** Plan covers the facility if it is property-wide (same site) or a coverage row exists for the facility or any ancestor. */
    private function covers(Membership $m, string $facilityId): bool
    {
        $fac = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->first(['site_id', 'organization_id']);
        if ($fac === null || Ids::fromBinary($fac->organization_id) !== Ids::normalize($m->organization_id)) {
            return false;
        }
        if ($m->plan->property_wide) {
            return true;
        }
        $chain = array_map(Ids::toBinary(...), FacilityTree::selfAndAncestors($facilityId));

        return DB::table('plan_coverage')->where('plan_id', Ids::toBinary($m->plan_id))->whereIn('facility_unit_id', $chain)->exists();
    }

    /** Coverage-level override (most specific covering row wins) else the term's snapshotted discount. Decimal string with 2dp. */
    private function discountFor(Membership $m, string $facilityId): string
    {
        $default = number_format((float) $m->getRawOriginal('member_discount_percent'), 2, '.', '');
        if ($m->plan->property_wide) {
            return $default;
        }
        foreach (FacilityTree::selfAndAncestors($facilityId) as $fid) { // nearest first
            $row = DB::table('plan_coverage')->where('plan_id', Ids::toBinary($m->plan_id))->where('facility_unit_id', Ids::toBinary($fid))->first(['discount_percent']);
            if ($row !== null) {
                return $row->discount_percent === null ? $default : number_format((float) $row->discount_percent, 2, '.', '');
            }
        }

        return $default;
    }

    private function resolveCard(array $in): ?MemberCard
    {
        $candidates = [];
        if (! empty($in['qrToken'])) {
            $uuid = CardCodec::verifyQr((string) $in['qrToken']);
            if ($uuid === null) {
                return null;
            }
            $candidates[] = [MemberCard::QR, (string) $in['qrToken']];
        }
        if (! empty($in['nfcUid'])) {
            $candidates[] = [MemberCard::NFC, CardCodec::normalizeNfc((string) $in['nfcUid'])];
        }
        if (! empty($in['membershipNumber'])) {
            $candidates[] = [MemberCard::MEMBER_ID, CardCodec::normalizeNumber((string) $in['membershipNumber'])];
        }
        foreach ($candidates as [$type, $ident]) {
            $card = MemberCard::query()->where('card_type', $type)->where('identifier', $ident)->where('status', 'ACTIVE')->first();
            if ($card !== null) {
                return $card;
            }
        }

        return null;
    }

    private function deny(string $reason, ?Membership $m = null, ?CarbonImmutable $now = null): array
    {
        return ['valid' => false, 'reason' => $reason, 'membership' => $m?->toApi(now: $now), 'entitlement' => null, 'consumed' => false, 'duplicate' => false];
    }

    private function admit(Membership $m, string $facilityId, int $guests, ?int $visit, string $discount, bool $duplicate, CarbonImmutable $now): array
    {
        $m->loadMissing(['plan', 'customer']);

        return [
            'valid' => true, 'reason' => null, 'membership' => $m->toApi(now: $now),
            'entitlement' => [
                'discountPercent' => $discount, 'guestsAdmitted' => $guests, 'guestAllowance' => $m->guest_allowance,
                'visitsRemaining' => $m->visit_limit === null ? null : max(0, $m->visit_limit - $m->visits_used),
                'inGrace' => $m->valid_until !== null && $now > $m->valid_until,
                'facilityId' => $facilityId,
            ],
            'visitNumber' => $visit, 'consumed' => $visit !== null && ! $duplicate, 'duplicate' => $duplicate,
        ];
    }
}
