<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Support\Fmt;
use App\Domain\Payments\Support\Ledger;
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
 * Cash drawer sessions. Declared cash vs system cash is computed from the immutable ledger (Ledger::cashTotals), never
 * from a running balance that could drift; closing snapshots expected / counted / variance and freezes the row.
 */
class CashSessionService
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    /** @return array<string, mixed> */
    public function open(string $facilityId, string $openingFloat, string $staffId): array
    {
        $facilityId = Ids::normalize($facilityId);
        ['org' => $org, 'site' => $site] = Tenantless::facility($facilityId);
        $openingFloat = Money::normalize($openingFloat);
        $id = Ids::uuid7();
        $deviceId = RequestContext::deviceId();

        return DB::transaction(function () use ($id, $facilityId, $org, $site, $openingFloat, $staffId, $deviceId) {
            try {
                DB::table('cash_session')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'site_id' => Ids::toBinary($site),
                    'facility_unit_id' => Ids::toBinary($facilityId), 'device_id' => Fmt::bin($deviceId), 'staff_id' => Ids::toBinary($staffId),
                    'opening_float' => $openingFloat, 'opened_at' => Fmt::now(),
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) { // uq_cs_open_staff / uq_cs_open_device: DB-level "one open session" guard
                    $existing = DB::table('cash_session')->where('status', 'OPEN')
                        ->where(fn ($q) => $q->where('staff_id', Ids::toBinary($staffId))->when($deviceId, fn ($q2) => $q2->orWhere('device_id', Ids::toBinary($deviceId))))
                        ->value('id');
                    throw ApiProblem::conflict('cash_session_already_open', 'A cash session is already open for this cashier or device.', $existing ? ['cashSessionId' => Ids::fromBinary($existing)] : []);
                }
                throw $e;
            }
            Audit::record('cash_session.open', 'CashSession', $id, new: ['openingFloat' => $openingFloat, 'facilityId' => $facilityId], facilityUnitId: $facilityId);
            Outbox::record('CashSessionOpened', 'CashSession', $id, ['cashSessionId' => $id, 'facilityId' => $facilityId, 'staffId' => $staffId, 'openingFloat' => $openingFloat], facilityId: $facilityId);

            return $this->present(DB::table('cash_session')->where('id', Ids::toBinary($id))->first());
        });
    }

    /** @return array<string, mixed> */
    public function close(string $sessionId, string $countedCash, ?string $note, string $staffId): array
    {
        $counted = Money::normalize($countedCash);
        if (Money::isValid($counted) === false || bccomp($counted, '0', 4) < 0) {
            throw ApiProblem::unprocessable('validation_failed', 'countedCash must be a non-negative amount.');
        }

        return DB::transaction(function () use ($sessionId, $counted, $note, $staffId) {
            // Exclusive lock: waits for in-flight payments (they hold a shared lock) and blocks new ones until we commit.
            $s = DB::selectOne('SELECT * FROM cash_session WHERE id = ? FOR UPDATE', [Ids::toBinary($sessionId)]) ?? throw ApiProblem::notFound('not_found', 'Cash session not found.');
            $facilityId = Ids::fromBinary($s->facility_unit_id);
            if (Ids::fromBinary($s->staff_id) !== $staffId
                && ! $this->permissions->can($staffId, 'cash_session.close_any', Scope::facility($facilityId))) {
                throw ApiProblem::permissionDenied('cash_session.close_any');
            }
            if ($s->status !== 'OPEN') {
                throw ApiProblem::conflict('cash_session_closed', 'This cash session is already closed.');
            }
            $totals = Ledger::cashTotals($sessionId);
            $expected = Ledger::expectedCash(Money::normalize((string) $s->opening_float), $totals);
            $variance = bcsub($counted, $expected, 4);
            $now = Fmt::now();
            DB::table('cash_session')->where('id', $s->id)->update([
                'status' => 'CLOSED', 'expected_cash' => $expected, 'counted_cash' => $counted, 'variance' => $variance,
                'closing_totals' => json_encode($totals, JSON_THROW_ON_ERROR), 'close_note' => $note, 'closed_by_staff_id' => Ids::toBinary($staffId),
                'closed_at' => $now, 'row_version' => DB::raw('row_version + 1'),
            ]);
            Audit::record('cash_session.close', 'CashSession', $sessionId,
                old: ['status' => 'OPEN'], new: ['status' => 'CLOSED', 'expectedCash' => $expected, 'countedCash' => $counted, 'variance' => $variance, 'totals' => $totals, 'note' => $note],
                facilityUnitId: $facilityId);
            Outbox::record('CashSessionClosed', 'CashSession', $sessionId, [
                'cashSessionId' => $sessionId, 'facilityId' => $facilityId, 'staffId' => Ids::fromBinary($s->staff_id),
                'expectedCash' => $expected, 'countedCash' => $counted, 'variance' => $variance, 'closedAt' => Fmt::iso($now),
            ], entityVersion: (int) $s->row_version + 1, facilityId: $facilityId);
            if (bccomp($variance, '0', 4) !== 0) {
                Audit::securityEvent('cash_session.variance', bccomp($variance, '0', 4) < 0 ? 'WARNING' : 'INFO', $staffId, null, ['cashSessionId' => $sessionId, 'variance' => $variance, 'facilityId' => $facilityId]);
            }

            return $this->present(DB::table('cash_session')->where('id', $s->id)->first());
        });
    }

    /** Paid-in / paid-out / safe drop. @return array<string, mixed> the updated session */
    public function recordMovement(string $sessionId, string $kind, string $amount, string $reason, string $staffId): array
    {
        $amount = Money::normalize($amount);

        return DB::transaction(function () use ($sessionId, $kind, $amount, $reason, $staffId) {
            $s = DB::selectOne('SELECT id, staff_id, status, facility_unit_id FROM cash_session WHERE id = ? FOR SHARE', [Ids::toBinary($sessionId)]) ?? throw ApiProblem::notFound('not_found', 'Cash session not found.');
            if ($s->status !== 'OPEN') {
                throw ApiProblem::conflict('cash_session_closed', 'This cash session is closed.');
            }
            if (Ids::fromBinary($s->staff_id) !== $staffId) {
                throw ApiProblem::forbidden('permission_denied', 'Only the cashier who opened the session can record movements.');
            }
            $id = Ids::uuid7();
            DB::table('cash_movement')->insert([
                'id' => Ids::toBinary($id), 'cash_session_id' => $s->id, 'kind' => $kind, 'amount' => $amount, 'reason' => $reason, 'staff_id' => Ids::toBinary($staffId),
            ]);
            Audit::record('cash_session.movement', 'CashSession', $sessionId, new: ['kind' => $kind, 'amount' => $amount, 'reason' => $reason, 'movementId' => $id], facilityUnitId: Ids::fromBinary($s->facility_unit_id));

            return $this->present(DB::table('cash_session')->where('id', $s->id)->first());
        });
    }

    /**
     * Contract `CashSession` (+ additive `currency`, `totals`, `note`, `closedByStaffId`). While OPEN, `expectedCash` is
     * computed live; once CLOSED it is the snapshot taken at close.
     *
     * @return array<string, mixed>
     */
    public function present(object $s): array
    {
        $id = Ids::fromBinary($s->id);
        $open = $s->status === 'OPEN';
        $totals = $open ? Ledger::cashTotals($id) : json_decode((string) $s->closing_totals, true);
        $expected = $open ? Ledger::expectedCash(Money::normalize((string) $s->opening_float), $totals) : Money::normalize((string) $s->expected_cash);
        // `nonCash` is a tender => amount MAP: an empty PHP array would serialise as `[]` (and a filled one as `{...}`), which strict clients cannot parse.
        $totals['nonCash'] = (object) ($totals['nonCash'] ?? []);

        return [
            'id' => $id,
            'facilityId' => Ids::fromBinary($s->facility_unit_id),
            'deviceId' => Fmt::uuid($s->device_id),
            'staffId' => Ids::fromBinary($s->staff_id),
            'status' => $s->status,
            'currency' => $s->currency,
            'openingFloat' => Money::normalize((string) $s->opening_float),
            'expectedCash' => $expected,
            'countedCash' => $s->counted_cash === null ? null : Money::normalize((string) $s->counted_cash),
            'variance' => $s->variance === null ? null : Money::normalize((string) $s->variance),
            'totals' => $totals,
            'note' => $s->close_note,
            'closedByStaffId' => Fmt::uuid($s->closed_by_staff_id),
            'openedAt' => Fmt::iso($s->opened_at),
            'closedAt' => Fmt::iso($s->closed_at),
        ];
    }
}
