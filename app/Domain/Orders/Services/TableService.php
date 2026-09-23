<?php

namespace App\Domain\Orders\Services;

use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/** Dining tables: status, seating (open), waiter assignment, transfer of orders between tables. */
final class TableService
{
    public const OPEN_ORDER_STATUSES = ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED', 'PENDING_APPROVAL'];

    public function __construct(private readonly Presenter $presenter, private readonly Realtime $realtime) {}

    public function find(string $tableId, bool $lock = false): object
    {
        $q = DB::table('dining_table')->where('id', Ids::toBinary($tableId))->where('is_active', 1);
        $lock && $q->lockForUpdate();
        $t = $q->first();
        if (! $t) {
            throw ApiProblem::notFound('not_found', 'Table not found.');
        }

        return $t;
    }

    /** @return array<string, mixed> */
    public function setStatus(string $tableId, string $status, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($tableId, $status, $ifMatch) {
            $t = $this->find($tableId, true);
            Authz::require('table.manage', Ids::fromBinary($t->facility_unit_id));
            Concurrency::assertVersion((int) $t->row_version, $ifMatch, 'table');
            if ($status === 'FREE' && $this->hasOpenOrders($t->id)) {
                throw ApiProblem::conflict('order_state_invalid', 'Table still has open orders; settle or void them first.');
            }
            $upd = ['status' => $status, 'row_version' => $t->row_version + 1];
            if ($status !== 'OCCUPIED') {
                $upd += ['occupied_by_staff_id' => null, 'occupied_session_id' => null, 'occupied_at' => null];
            }
            DB::table('dining_table')->where('id', $t->id)->update($upd);
            Audit::record('table.status.set', 'DiningTable', $tableId, old: ['status' => $t->status], new: ['status' => $status], facilityUnitId: Ids::fromBinary($t->facility_unit_id));
            $this->realtime->tableUpdated($t->id);

            return $this->present($tableId);
        });
    }

    /** Seat guests. Same session again = no-op 200; different staff on an occupied table = 409 concurrency_conflict. */
    public function open(string $tableId): array
    {
        return DB::transaction(function () use ($tableId) {
            $t = $this->find($tableId, true);
            $fid = Ids::fromBinary($t->facility_unit_id);
            Authz::require('order.create', $fid);
            $staff = Authz::staffId();
            $session = RequestContext::sessionId();
            if ($t->status === 'OCCUPIED') {
                $same = Fmt::u($t->occupied_by_staff_id) === $staff || ($session && Fmt::u($t->occupied_session_id) === $session);
                if ($same || $t->occupied_by_staff_id === null) {
                    return $this->present($tableId);
                }
                throw ApiProblem::conflict('concurrency_conflict', 'This table is already occupied by another staff member.');
            }
            DB::table('dining_table')->where('id', $t->id)->update([
                'status' => 'OCCUPIED', 'occupied_by_staff_id' => Ids::toBinary($staff), 'occupied_session_id' => Fmt::b($session),
                'occupied_at' => Fmt::now(), 'row_version' => $t->row_version + 1,
            ]);
            $this->realtime->tableUpdated($t->id);

            return $this->present($tableId);
        });
    }

    /** Used by order creation: mark occupied (no owner conflict: several orders/attendants may share a table). */
    public function occupyForOrder(object $lockedTable): void
    {
        if ($lockedTable->status === 'OCCUPIED') {
            return;
        }
        DB::table('dining_table')->where('id', $lockedTable->id)->update([
            'status' => 'OCCUPIED', 'occupied_by_staff_id' => Fmt::b(RequestContext::staffId()), 'occupied_session_id' => Fmt::b(RequestContext::sessionId()),
            'occupied_at' => Fmt::now(), 'row_version' => $lockedTable->row_version + 1,
        ]);
        $this->realtime->tableUpdated($lockedTable->id);
    }

    /** After an order ends: free (void) / needs-cleaning (settled) when nothing else is open on the table. */
    public function releaseIfIdle(?string $tableBin, string $newStatus): void
    {
        if ($tableBin === null) {
            return;
        }
        $t = DB::table('dining_table')->where('id', $tableBin)->lockForUpdate()->first();
        if (! $t || $t->status !== 'OCCUPIED' || $this->hasOpenOrders($tableBin) || DB::table('tab')->where('dining_table_id', $tableBin)->where('status', 'OPEN')->exists()) {
            return;
        }
        DB::table('dining_table')->where('id', $tableBin)->update([
            'status' => $newStatus, 'occupied_by_staff_id' => null, 'occupied_session_id' => null, 'occupied_at' => null, 'row_version' => $t->row_version + 1,
        ]);
        $this->realtime->tableUpdated($tableBin);
    }

    /** @return array<string, mixed> */
    public function assign(string $tableId, ?string $staffId): array
    {
        return DB::transaction(function () use ($tableId, $staffId) {
            $t = $this->find($tableId, true);
            $fid = Ids::fromBinary($t->facility_unit_id);
            Authz::require('table.manage', $fid);
            if ($staffId !== null && ! DB::table('staff')->where('id', Ids::toBinary($staffId))->where('is_active', 1)->exists()) {
                throw ApiProblem::unprocessable('validation_failed', 'Unknown staff member.');
            }
            DB::table('dining_table')->where('id', $t->id)->update(['assigned_staff_id' => Fmt::b($staffId), 'row_version' => $t->row_version + 1]);
            Audit::record('table.assign', 'DiningTable', $tableId, old: ['assignedStaffId' => Fmt::u($t->assigned_staff_id)], new: ['assignedStaffId' => $staffId], facilityUnitId: $fid);
            $this->realtime->tableUpdated($t->id);

            return $this->present($tableId);
        });
    }

    /**
     * Move open orders (all, or `orderIds`) and the open tab from one table to another at the same facility.
     * Locks both tables in id order (deadlock-safe), then the orders.
     *
     * @param  list<string>|null  $orderIds
     * @return array{from: array<string, mixed>, to: array<string, mixed>}
     */
    public function transfer(string $fromId, string $toId, ?array $orderIds, ?int $ifMatchFrom = null): array
    {
        if ($fromId === $toId) {
            throw ApiProblem::unprocessable('validation_failed', 'Source and target tables must differ.');
        }

        return DB::transaction(function () use ($fromId, $toId, $orderIds, $ifMatchFrom) {
            $bins = [Ids::toBinary($fromId), Ids::toBinary($toId)];
            sort($bins);
            foreach ($bins as $b) {
                DB::table('dining_table')->where('id', $b)->lockForUpdate()->first();
            }
            $from = $this->find($fromId);
            $to = $this->find($toId);
            if ($from->facility_unit_id !== $to->facility_unit_id) {
                throw ApiProblem::unprocessable('facility_mismatch', 'Tables belong to different facilities.');
            }
            $fid = Ids::fromBinary($from->facility_unit_id);
            Authz::require('table.manage', $fid);
            Concurrency::assertVersion((int) $from->row_version, $ifMatchFrom, 'table');
            if (! in_array($to->status, ['FREE', 'OCCUPIED', 'RESERVED'], true)) {
                throw ApiProblem::conflict('order_state_invalid', 'The target table is not available (needs cleaning).');
            }
            $q = DB::table('order')->where('dining_table_id', $from->id)->whereIn('status', self::OPEN_ORDER_STATUSES)->orderBy('id')->lockForUpdate();
            if ($orderIds !== null) {
                $q->whereIn('id', array_map(Ids::toBinary(...), $orderIds));
            }
            $orders = $q->get();
            if ($orderIds !== null && $orders->count() !== count(array_unique($orderIds))) {
                throw ApiProblem::unprocessable('validation_failed', 'Some orders are not open on the source table.');
            }
            if ($orders->isEmpty() && $orderIds === null && ! DB::table('tab')->where('dining_table_id', $from->id)->where('status', 'OPEN')->exists()) {
                throw ApiProblem::conflict('order_state_invalid', 'Nothing to transfer from this table.');
            }
            foreach ($orders as $o) {
                DB::table('order')->where('id', $o->id)->update(['dining_table_id' => $to->id, 'row_version' => $o->row_version + 1]);
                DB::table('prep_ticket')->where('order_id', $o->id)->update(['table_label' => $to->label]);
                $this->realtime->orderUpdated($o, ['table']);
            }
            if ($orderIds === null) {
                DB::table('tab')->where('dining_table_id', $from->id)->where('status', 'OPEN')->update(['dining_table_id' => $to->id]);
            }
            if ($to->status !== 'OCCUPIED') {
                DB::table('dining_table')->where('id', $to->id)->update(['status' => 'OCCUPIED', 'occupied_by_staff_id' => Fmt::b(RequestContext::staffId()), 'occupied_at' => Fmt::now(), 'row_version' => $to->row_version + 1]);
            } else {
                DB::table('dining_table')->where('id', $to->id)->update(['row_version' => $to->row_version + 1]);
            }
            $fromRow = DB::table('dining_table')->where('id', $from->id)->first();
            $stillOpen = $this->hasOpenOrders($from->id) || DB::table('tab')->where('dining_table_id', $from->id)->where('status', 'OPEN')->exists();
            DB::table('dining_table')->where('id', $from->id)->update($stillOpen ? ['row_version' => $fromRow->row_version + 1] : [
                'status' => 'NEEDS_CLEANING', 'occupied_by_staff_id' => null, 'occupied_session_id' => null, 'occupied_at' => null, 'row_version' => $fromRow->row_version + 1,
            ]);
            Audit::record('table.transfer', 'DiningTable', $fromId,
                old: ['tableId' => $fromId], new: ['toTableId' => $toId, 'orderIds' => $orders->map(fn ($o) => Ids::fromBinary($o->id))->all()], facilityUnitId: $fid);
            $this->realtime->tableUpdated($from->id);
            $this->realtime->tableUpdated($to->id);

            return ['from' => $this->present($fromId), 'to' => $this->present($toId)];
        });
    }

    public function hasOpenOrders(string $tableBin): bool
    {
        return DB::table('order')->where('dining_table_id', $tableBin)->whereIn('status', self::OPEN_ORDER_STATUSES)->exists();
    }

    /** @return array<string, mixed> */
    public function present(string $tableId): array
    {
        return $this->presenter->table($this->find($tableId));
    }
}
