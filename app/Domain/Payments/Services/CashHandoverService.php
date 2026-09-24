<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Orders\Services\Realtime;
use App\Domain\Payments\Broadcast\CashHandoverReceived;
use App\Domain\Payments\Support\CollectionRules;
use App\Domain\Payments\Support\Fmt;
use App\Domain\Payments\Support\Tenantless;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cash handover (docs/WAITER_COLLECTION.md section 6): the waiter declares what they hand over, the cashier counts it.
 * Variance = counted - declared (negative = short). The waiter's cash-in-hand (append-only ledger) is reduced by the DECLARED amount at
 * receipt; the variance is its own audited record (with supervisor sign-off above `cash_handover_max_variance`).
 * Lock order: handover row -> waiter's staff row (the cash-in-hand serialisation point, same as collections).
 */
class CashHandoverService
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly CollectionPolicyService $policy,
        private readonly CollectionRules $rules,
        private readonly PermissionChecker $permissions,
        private readonly Realtime $realtime,
    ) {}

    /** @return array{body: array<string, mixed>, replayed: bool} */
    public function declare(array $in, string $staffId): array
    {
        $facilityId = isset($in['facilityId']) ? Ids::normalize($in['facilityId']) : ($this->policy->defaultFacility($staffId) ?? throw ApiProblem::unprocessable('validation_failed', 'facilityId is required.', ['facilityId' => ['Required.']]));
        if (! $this->permissions->can($staffId, 'cash_handover.create', Scope::facility($facilityId))) {
            throw ApiProblem::permissionDenied('cash_handover.create');
        }
        $declared = Money::normalize($in['declaredAmount']);
        $fp = hash('sha256', Audit::canonicalize(['facilityId' => $facilityId, 'declaredAmount' => $declared, 'note' => $in['note'] ?? null]));

        return DB::transaction(function () use ($in, $staffId, $facilityId, $declared, $fp) {
            DB::selectOne('SELECT id FROM staff WHERE id = ? FOR UPDATE', [Ids::toBinary($staffId)]);
            if (isset($in['id']) && ($ex = DB::table('cash_handover')->where('id', Ids::toBinary($in['id']))->first()) !== null) {
                if (! hash_equals((string) $ex->client_fingerprint, $fp) || Ids::fromBinary($ex->waiter_staff_id) !== $staffId) {
                    throw ApiProblem::conflict('concurrency_conflict', 'A handover with this id already exists with a different body.');
                }

                return ['body' => $this->present($ex), 'replayed' => true];
            }
            $inHand = $this->collections->cashInHand($staffId);
            if (! $this->policy->cashAllowed($staffId, $facilityId) && bccomp($inHand, '0', 4) <= 0) {
                throw ApiProblem::forbidden('cash_holding_not_allowed', 'Cash holding is not enabled for you; there is no cash to hand over.');
            }
            $open = (string) (DB::table('cash_handover')->where('waiter_staff_id', Ids::toBinary($staffId))->where('status', 'PENDING_RECEIPT')->sum('declared_amount') ?? '0');
            if (bccomp(bcadd(Money::normalize($open), $declared, 4), $inHand, 4) > 0) {
                throw ApiProblem::unprocessable('amount_mismatch', 'You cannot hand over more than the cash you are holding.', ['declaredAmount' => ["Cash in hand is {$inHand} (of which ".Money::normalize($open).' is already declared).']]);
            }
            ['org' => $org, 'site' => $site] = Tenantless::facility($facilityId);
            $id = $in['id'] ?? Ids::uuid7();
            DB::table('cash_handover')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'site_id' => Ids::toBinary($site), 'facility_unit_id' => Ids::toBinary($facilityId),
                'waiter_staff_id' => Ids::toBinary($staffId), 'device_id' => Fmt::bin(RequestContext::deviceId()), 'status' => 'PENDING_RECEIPT',
                'expected_in_hand' => $inHand, 'declared_amount' => $declared, 'note' => $in['note'] ?? null, 'client_fingerprint' => $fp,
            ]);
            Audit::record('cash_handover.declare', 'CashHandover', $id, new: ['declaredAmount' => $declared, 'expectedInHand' => $inHand, 'note' => $in['note'] ?? null],
                organizationId: $org, siteId: $site, actorStaffId: $staffId, facilityUnitId: $facilityId);

            return ['body' => $this->present(DB::table('cash_handover')->where('id', Ids::toBinary($id))->first()), 'replayed' => false];
        });
    }

    /** @return array<string, mixed> */
    public function receive(string $handoverId, array $in, string $staffId): array
    {
        return DB::transaction(function () use ($handoverId, $in, $staffId) {
            $h = $this->lock($handoverId);
            $facilityId = Ids::fromBinary($h->facility_unit_id);
            if (! $this->permissions->can($staffId, 'cash_handover.receive', Scope::facility($facilityId))) {
                throw ApiProblem::permissionDenied('cash_handover.receive');
            }
            $waiter = Ids::fromBinary($h->waiter_staff_id);
            if ($waiter === $staffId) {
                throw ApiProblem::forbidden('self_receipt_forbidden', 'You cannot receive your own handover.');
            }
            if ($h->status !== 'PENDING_RECEIPT') {
                throw ApiProblem::conflict('already_received', 'This handover has already been received.', ['status' => $h->status]);
            }
            DB::selectOne('SELECT id FROM staff WHERE id = ? FOR UPDATE', [$h->waiter_staff_id]);
            $declared = Money::normalize((string) $h->declared_amount);
            $inHand = $this->collections->cashInHand($waiter);
            if (bccomp($declared, $inHand, 4) > 0) {
                throw ApiProblem::conflict('balance_changed', 'The waiter is holding less cash than declared.', ['cashInHand' => $inHand, 'declaredAmount' => $declared]);
            }
            $counted = Money::normalize($in['countedAmount']);
            $variance = bcsub($counted, $declared, 4);
            $needsSignoff = bccomp(ltrim($variance, '-'), $this->rules->forFacility($facilityId)['handoverMaxVariance'], 4) > 0;
            $now = Fmt::now();
            DB::table('cash_handover')->where('id', $h->id)->update([
                'status' => $needsSignoff ? 'PENDING_SIGNOFF' : 'RECEIVED', 'counted_amount' => $counted, 'variance' => $variance, 'requires_signoff' => $needsSignoff ? 1 : 0,
                'receive_note' => $in['note'] ?? null, 'received_by' => Ids::toBinary($staffId), 'received_at' => $now, 'row_version' => $h->row_version + 1,
            ]);
            DB::table('cash_in_hand_entry')->insert([
                'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $h->organization_id, 'site_id' => $h->site_id, 'facility_unit_id' => $h->facility_unit_id,
                'staff_id' => $h->waiter_staff_id, 'kind' => 'HANDOVER', 'amount' => '-'.$declared, 'handover_id' => $h->id, 'created_at' => $now,
            ]);
            $org = Ids::fromBinary($h->organization_id);
            $site = Ids::fromBinary($h->site_id);
            Audit::record('cash_handover.receive', 'CashHandover', $handoverId, old: ['status' => 'PENDING_RECEIPT'], new: [
                'status' => $needsSignoff ? 'PENDING_SIGNOFF' : 'RECEIVED', 'declaredAmount' => $declared, 'countedAmount' => $counted, 'variance' => $variance, 'waiterStaffId' => $waiter,
            ], organizationId: $org, siteId: $site, actorStaffId: $staffId, facilityUnitId: $facilityId);
            if (bccomp($variance, '0', 4) !== 0) {
                Audit::securityEvent('cash_handover.variance', $needsSignoff ? 'CRITICAL' : 'WARNING', $staffId, null, [
                    'handoverId' => $handoverId, 'waiterStaffId' => $waiter, 'declaredAmount' => $declared, 'countedAmount' => $counted, 'variance' => $variance,
                    'kind' => bccomp($variance, '0', 4) < 0 ? 'SHORT' : 'OVER', 'requiresSignoff' => $needsSignoff,
                ]);
            }
            Outbox::record('CashHandoverRecorded', 'CashHandover', $handoverId, [
                'handoverId' => $handoverId, 'facilityId' => $facilityId, 'waiterStaffId' => $waiter, 'receivedByStaffId' => $staffId, 'declaredAmount' => $declared,
                'countedAmount' => $counted, 'variance' => $variance, 'requiresSignoff' => $needsSignoff, 'currency' => 'NGN',
            ], organizationId: $org, siteId: $site, facilityId: $facilityId);
            $fresh = DB::table('cash_handover')->where('id', $h->id)->first();
            $channels = [$this->realtime->facilityOrders($facilityId)];
            if ($h->device_id !== null) {
                $channels[] = 'device.'.Ids::fromBinary($h->device_id);
            }
            event(new CashHandoverReceived($channels, ['handover' => $this->present($fresh)]));

            return $this->present($fresh);
        });
    }

    /** @return array<string, mixed> */
    public function signoff(string $handoverId, ?string $note, string $staffId): array
    {
        return DB::transaction(function () use ($handoverId, $note, $staffId) {
            $h = $this->lock($handoverId);
            $facilityId = Ids::fromBinary($h->facility_unit_id);
            if (! $this->permissions->can($staffId, 'cash_handover.signoff', Scope::facility($facilityId))) {
                throw ApiProblem::permissionDenied('cash_handover.signoff');
            }
            if ($h->status === 'SIGNED_OFF') {
                return $this->present($h);
            }
            if ($h->status !== 'PENDING_SIGNOFF') {
                throw ApiProblem::conflict('handover_state_invalid', "A handover that is {$h->status} does not need sign-off.", ['status' => $h->status]);
            }
            if (Ids::fromBinary($h->received_by) === $staffId) {
                throw ApiProblem::forbidden('self_signoff_forbidden', 'The receiver cannot sign off their own count.');
            }
            DB::table('cash_handover')->where('id', $h->id)->update([
                'status' => 'SIGNED_OFF', 'signoff_by' => Ids::toBinary($staffId), 'signoff_at' => Fmt::now(), 'signoff_note' => $note, 'row_version' => $h->row_version + 1,
            ]);
            Audit::record('cash_handover.signoff', 'CashHandover', $handoverId, old: ['status' => 'PENDING_SIGNOFF'], new: ['status' => 'SIGNED_OFF', 'variance' => Money::normalize((string) $h->variance), 'note' => $note],
                organizationId: Ids::fromBinary($h->organization_id), siteId: Ids::fromBinary($h->site_id), actorStaffId: $staffId, facilityUnitId: $facilityId);

            return $this->present(DB::table('cash_handover')->where('id', $h->id)->first());
        });
    }

    /** @return array<string, mixed> */
    public function position(string $staffId, ?string $facilityId): array
    {
        $bin = Ids::toBinary($staffId);
        $facilityId ??= $this->policy->defaultFacility($staffId);
        $eff = $this->policy->effective($staffId, $facilityId);
        $inHand = $this->collections->cashInHand($staffId);
        $limit = $eff['cashHolding']['limit'];
        $lastHandover = DB::table('cash_in_hand_entry')->where('staff_id', $bin)->where('kind', 'HANDOVER')->max('created_at');
        $oldest = bccomp($inHand, '0', 4) > 0
            ? DB::table('cash_in_hand_entry')->where('staff_id', $bin)->where('kind', 'COLLECTED')->when($lastHandover, fn ($q) => $q->where('created_at', '>', $lastHandover))->min('created_at')
            : null;
        $pending = DB::table('payment_collection as c')->join('payment as p', 'p.id', '=', 'c.payment_id')->where('c.collected_by_staff_id', $bin)
            ->whereIn('p.status', ['PENDING_CONFIRMATION', 'AUTHORIZING']);
        $short = (string) (DB::table('cash_handover')->where('waiter_staff_id', $bin)->where('status', 'PENDING_SIGNOFF')->where('variance', '<', 0)->sum('variance') ?? '0');

        return [
            'staffId' => $staffId, 'currency' => 'NGN', 'cashInHand' => $inHand, 'limit' => $limit,
            'handoverRequired' => $limit !== null && bccomp($inHand, $limit, 4) >= 0,
            'cashHoldingAllowed' => $eff['cashHolding']['allowed'],
            'oldestUncollectedAt' => $oldest === null ? null : Fmt::iso((string) $oldest),
            'pendingCollections' => (clone $pending)->count(), 'pendingCollectionsAmount' => Money::normalize((string) ((clone $pending)->sum('p.amount') ?? '0')),
            'openHandovers' => (int) DB::table('cash_handover')->where('waiter_staff_id', $bin)->whereIn('status', ['PENDING_RECEIPT', 'PENDING_SIGNOFF'])->count(),
            'unsignedShortfall' => Money::normalize(ltrim(Money::normalize($short), '-')),
        ];
    }

    /** @return array<string, mixed> */
    public function present(object $h): array
    {
        $v = $h->variance === null ? null : Money::normalize((string) $h->variance);

        return [
            'id' => Ids::fromBinary($h->id), 'facilityId' => Ids::fromBinary($h->facility_unit_id), 'waiterStaffId' => Ids::fromBinary($h->waiter_staff_id), 'status' => $h->status,
            'expectedInHand' => Money::normalize((string) $h->expected_in_hand), 'declaredAmount' => Money::normalize((string) $h->declared_amount),
            'countedAmount' => $h->counted_amount === null ? null : Money::normalize((string) $h->counted_amount), 'variance' => $v,
            'varianceKind' => $v === null ? null : (bccomp($v, '0', 4) === 0 ? 'EXACT' : (bccomp($v, '0', 4) > 0 ? 'OVER' : 'SHORT')),
            'requiresSignoff' => (bool) $h->requires_signoff, 'note' => $h->note,
            'receivedByStaffId' => Fmt::uuid($h->received_by), 'receivedAt' => Fmt::iso($h->received_at),
            'signedOffByStaffId' => Fmt::uuid($h->signoff_by), 'signedOffAt' => Fmt::iso($h->signoff_at), 'createdAt' => Fmt::iso($h->created_at),
        ];
    }

    private function lock(string $handoverId): object
    {
        $h = Ids::isUuid($handoverId) ? DB::selectOne('SELECT * FROM cash_handover WHERE id = ? FOR UPDATE', [Ids::toBinary($handoverId)]) : null;

        return $h ?? throw ApiProblem::notFound('not_found', 'Handover not found.');
    }
}
