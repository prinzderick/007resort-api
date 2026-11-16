<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Broadcast\TabHandoverUpdated;
use App\Support\Api\Authz;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Waiter-only service flow (facility rule `service_flow = WAITER_ONLY`): who owns which open tabs, and hand-over of them.
 *
 *  - Ownership = `order.responsible_staff_id` (NULL means the creator). `created_by` is never rewritten.
 *  - A waiter sees their own unpaid orders/tabs; `waiterflow.manage` (supervisor/manager/owner) sees everyone's.
 *  - Hand-over: the giver lists open orders for another waiter, the receiver must accept on their tablet. Nothing moves
 *    before that. A supervisor can reassign directly (recorded as a FORCED hand-over with a reason).
 *  - Every step is audited (hash-chained audit log) and announced on the facility channel.
 */
final class WaiterFlowService
{
    public const EXPIRY_MINUTES = 30;

    private const OPEN = ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED'];

    public function __construct(private readonly OperatingRules $rules, private readonly Realtime $realtime) {}

    private function requireFlow(string $facilityId): void
    {
        if (! $this->rules->waiterOnly($facilityId)) {
            throw ApiProblem::conflict('flow_not_enabled', 'The waiter-only service flow is not switched on for this facility.');
        }
    }

    private function mayManage(string $facilityId): bool
    {
        return Authz::can('waiterflow.manage', $facilityId);
    }

    private function requireWaiterRights(string $facilityId): void
    {
        if (! Authz::can('waiterflow.settle', $facilityId) && ! $this->mayManage($facilityId)) {
            throw ApiProblem::permissionDenied('waiterflow.settle');
        }
    }

    // ---- open tabs ---------------------------------------------------------------------------------------------------

    /**
     * Unpaid, not-voided orders of the facility grouped by tab (or alone). A waiter gets their own; a manager gets everyone's
     * (optionally narrowed to `$onlyStaffId`).
     *
     * @return array<string, mixed>
     */
    public function openItems(string $facilityId, ?string $onlyStaffId = null): array
    {
        $this->requireWaiterRights($facilityId);
        $manage = $this->mayManage($facilityId);
        $me = Authz::staffId();
        $scope = $manage ? $onlyStaffId : $me;

        $q = DB::table('order as o')->leftJoin('dining_table as dt', 'dt.id', '=', 'o.dining_table_id')
            ->where('o.facility_unit_id', Ids::toBinary($facilityId))
            ->whereIn('o.status', [...self::OPEN, 'PENDING_APPROVAL'])
            ->whereRaw('o.total - o.amount_paid > 0')
            ->select(['o.*', 'dt.label as table_label']);
        if ($scope !== null) {
            $q->whereRaw('COALESCE(o.responsible_staff_id, o.created_by) = ?', [Ids::toBinary($scope)]);
        }
        $rows = $q->orderBy('o.id')->get();

        $lines = $rows->isEmpty() ? collect() : DB::table('order_line')->whereIn('order_id', $rows->pluck('id')->all())
            ->whereNotIn('status', ['REMOVED', 'VOIDED'])->orderBy('line_no')->get(['order_id', 'product_name', 'quantity'])->groupBy(fn ($l) => Ids::fromBinary($l->order_id));
        $tabs = DB::table('tab')->whereIn('id', $rows->pluck('tab_id')->filter()->unique()->values()->all())->get()->keyBy(fn ($t) => Ids::fromBinary($t->id));
        $pending = $this->pendingHandoverOrders($facilityId);

        $groups = [];
        foreach ($rows as $o) {
            $oid = Ids::fromBinary($o->id);
            $tabId = Fmt::u($o->tab_id);
            $key = $tabId ?? $oid;
            $owner = Ids::fromBinary($o->responsible_staff_id ?? $o->created_by);
            $tab = $tabId ? ($tabs[$tabId] ?? null) : null;
            $balance = Money::of($o->total)->sub(Money::of($o->amount_paid))->amount;
            $groups[$key] ??= [
                'key' => $key, 'tabId' => $tabId, 'tableId' => Fmt::u($o->dining_table_id), 'tableLabel' => $o->table_label,
                'customerName' => $tab->customer_name ?? $o->customer_name, 'ownerStaffId' => $owner, 'ownerName' => null,
                'orders' => [], 'total' => '0.0000', 'paid' => '0.0000', 'balance' => '0.0000', 'oldestAt' => Fmt::ts($o->created_at),
                'allServed' => true, 'handoverPending' => false,
            ];
            $g = &$groups[$key];
            $g['orders'][] = [
                'id' => $oid, 'number' => $o->order_number, 'status' => $o->status, 'total' => Fmt::money($o->total),
                'paid' => Fmt::money($o->amount_paid), 'balance' => $balance, 'createdAt' => Fmt::ts($o->created_at), 'servedAt' => Fmt::ts($o->served_at),
                'ownerStaffId' => $owner, 'canPay' => $o->status === 'SERVED',
                'lines' => ($lines[$oid] ?? collect())->map(fn ($l) => ['name' => $l->product_name, 'quantity' => (int) $l->quantity])->values()->all(),
            ];
            $g['total'] = bcadd($g['total'], Fmt::money($o->total), 4);
            $g['paid'] = bcadd($g['paid'], Fmt::money($o->amount_paid), 4);
            $g['balance'] = bcadd($g['balance'], $balance, 4);
            $g['allServed'] = $g['allServed'] && $o->status === 'SERVED';
            $g['handoverPending'] = $g['handoverPending'] || isset($pending[$oid]);
            unset($g);
        }

        $names = $this->staffNames(array_values(array_unique(array_map(fn ($g) => $g['ownerStaffId'], $groups))));
        $waiters = [];
        foreach ($groups as &$g) {
            $g['ownerName'] = $names[$g['ownerStaffId']] ?? null;
            $w = &$waiters[$g['ownerStaffId']];
            $w ??= ['staffId' => $g['ownerStaffId'], 'name' => $g['ownerName'], 'tabs' => 0, 'orders' => 0, 'balance' => '0.0000'];
            $w['tabs']++;
            $w['orders'] += count($g['orders']);
            $w['balance'] = bcadd($w['balance'], $g['balance'], 4);
            unset($w);
        }
        unset($g);
        $list = array_values($groups);
        usort($list, fn ($a, $b) => strcmp((string) $a['oldestAt'], (string) $b['oldestAt']));

        return [
            'facilityId' => $facilityId,
            'generatedAt' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'scope' => $manage && $onlyStaffId === null ? 'ALL' : 'OWN',
            'groups' => $list,
            'waiters' => $manage ? array_values($waiters) : [],
            'totals' => ['tabs' => count($list), 'orders' => array_sum(array_map(fn ($g) => count($g['orders']), $list)), 'balance' => array_reduce($list, fn ($c, $g) => bcadd($c, $g['balance'], 4), '0.0000')],
        ];
    }

    /**
     * Colleagues a tab can be handed to: active staff who can take orders at the facility (the caller excluded).
     *
     * @return list<array{staffId: string, name: string, staffNumber: string}>
     */
    public function waiters(string $facilityId): array
    {
        $this->requireWaiterRights($facilityId);
        $me = Authz::staffId();
        $out = [];
        $rows = DB::table('staff')->where('organization_id', Ids::toBinary(Tenant::organizationId()))->where('is_active', 1)->whereNull('deleted_at')
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'staff_number']);
        foreach ($rows as $s) {
            $id = Ids::fromBinary($s->id);
            if ($id === $me || ! Authz::can('order.create', $facilityId, $id) || ! Authz::can('waiterflow.settle', $facilityId, $id)) {
                continue;
            }
            $out[] = ['staffId' => $id, 'name' => trim($s->first_name.' '.$s->last_name), 'staffNumber' => $s->staff_number];
        }

        return $out;
    }

    // ---- hand-over ---------------------------------------------------------------------------------------------------

    /**
     * @param  list<string>|null  $orderIds  null = every open order of the giver at the facility
     * @return array<string, mixed>
     */
    public function requestHandover(string $facilityId, string $toStaffId, ?array $orderIds, ?string $note): array
    {
        $facilityId = Ids::normalize($facilityId);
        $toStaffId = Ids::normalize($toStaffId);
        $me = Authz::staffId();
        $this->requireFlow($facilityId);
        $this->requireWaiterRights($facilityId);
        if ($toStaffId === $me) {
            throw ApiProblem::unprocessable('validation_failed', 'Choose another waiter to hand over to.', ['toStaffId' => ['Cannot hand over to yourself.']]);
        }
        $this->assertReceiver($toStaffId, $facilityId);

        return DB::transaction(function () use ($facilityId, $toStaffId, $orderIds, $note, $me) {
            $this->expireStale($facilityId);
            $orders = $this->myOpenOrders($facilityId, $me, $orderIds, true);
            if ($orders->isEmpty()) {
                throw ApiProblem::conflict('nothing_to_hand_over', 'You have no open orders to hand over.');
            }
            foreach ($orders as $o) {
                if ($this->orderHasPendingMoney($o->id)) {
                    throw ApiProblem::conflict('collection_pending', "Order {$o->order_number} has money awaiting confirmation. Settle that first.", ['orderId' => Ids::fromBinary($o->id)]);
                }
                if (DB::table('tab_handover_item as i')->join('tab_handover as h', 'h.id', '=', 'i.handover_id')->where('i.order_id', $o->id)->where('h.status', 'PENDING')->exists()) {
                    throw ApiProblem::conflict('handover_pending', "Order {$o->order_number} is already part of a pending hand-over.", ['orderId' => Ids::fromBinary($o->id)]);
                }
            }
            $fac = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->first();
            $id = Ids::uuid7();
            $expires = now('UTC')->addMinutes(self::EXPIRY_MINUTES);
            DB::table('tab_handover')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => $fac->organization_id, 'site_id' => $fac->site_id, 'facility_unit_id' => $fac->id,
                'from_staff_id' => Ids::toBinary($me), 'to_staff_id' => Ids::toBinary($toStaffId), 'status' => 'PENDING', 'note' => $note,
                'expires_at' => $expires->format('Y-m-d H:i:s.u'), 'device_id' => RequestContext::deviceId() ? Ids::toBinary(RequestContext::deviceId()) : null,
            ]);
            foreach ($orders as $o) {
                DB::table('tab_handover_item')->insert(['handover_id' => Ids::toBinary($id), 'order_id' => $o->id, 'tab_id' => $o->tab_id]);
            }
            Audit::record('tab.handover.request', 'TabHandover', $id, new: [
                'from' => $me, 'to' => $toStaffId, 'orderIds' => $orders->map(fn ($o) => Ids::fromBinary($o->id))->all(), 'note' => $note,
                'expiresAt' => $expires->format('Y-m-d\TH:i:s.v\Z'),
            ], facilityUnitId: $facilityId);
            $this->announce($facilityId, $id, 'PENDING', $me, $toStaffId);

            return $this->present($id);
        });
    }

    /** @return array<string, mixed> */
    public function accept(string $handoverId): array
    {
        $me = Authz::staffId();

        return DB::transaction(function () use ($handoverId, $me) {
            $h = $this->lockHandover($handoverId);
            $facilityId = Ids::fromBinary($h->facility_unit_id);
            if (Ids::fromBinary($h->to_staff_id) !== $me) {
                throw ApiProblem::forbidden('not_the_receiver', 'Only the waiter this was handed to can accept it.');
            }
            $this->assertPending($h);
            $items = DB::table('tab_handover_item')->where('handover_id', $h->id)->orderBy('order_id')->get();
            $orders = DB::table('order')->whereIn('id', $items->pluck('order_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($o) => Ids::fromBinary($o->id));
            $from = Ids::fromBinary($h->from_staff_id);
            $moved = [];
            $skipped = [];
            $tabIds = [];
            foreach ($items as $i) {
                $oid = Ids::fromBinary($i->order_id);
                $o = $orders[$oid] ?? null;
                $owner = $o ? Ids::fromBinary($o->responsible_staff_id ?? $o->created_by) : null;
                $open = $o && in_array($o->status, self::OPEN, true) && bccomp(Money::normalize((string) $o->total), Money::normalize((string) $o->amount_paid), 4) > 0;
                if (! $open || $owner !== $from) {
                    $skipped[] = $oid; // paid, voided or reassigned meanwhile

                    continue;
                }
                DB::table('order')->where('id', $o->id)->update(['responsible_staff_id' => Ids::toBinary($me), 'row_version' => $o->row_version + 1]);
                DB::table('tab_handover_item')->where('handover_id', $h->id)->where('order_id', $o->id)->update(['moved' => 1]);
                Audit::record('order.reassign', 'Order', $oid, old: ['responsibleStaffId' => $from], new: ['responsibleStaffId' => $me, 'handoverId' => Ids::fromBinary($h->id)], facilityUnitId: $facilityId);
                $moved[] = $oid;
                if ($o->tab_id !== null) {
                    $tabIds[Ids::fromBinary($o->tab_id)] = $o->tab_id;
                }
            }
            foreach ($tabIds as $tabId => $tabBin) {
                DB::table('tab')->where('id', $tabBin)->where('status', 'OPEN')->update(['responsible_staff_id' => Ids::toBinary($me)]);
                DB::table('tab')->where('id', $tabBin)->increment('row_version');
            }
            DB::table('tab_handover')->where('id', $h->id)->update([
                'status' => 'ACCEPTED', 'decided_by_staff_id' => Ids::toBinary($me), 'decided_at' => Fmt::now(), 'row_version' => $h->row_version + 1,
            ]);
            Audit::record('tab.handover.accept', 'TabHandover', Ids::fromBinary($h->id), old: ['status' => 'PENDING'], new: [
                'status' => 'ACCEPTED', 'from' => $from, 'to' => $me, 'moved' => $moved, 'skipped' => $skipped, 'tabIds' => array_keys($tabIds),
            ], facilityUnitId: $facilityId);
            foreach ($moved as $oid) {
                $this->realtime->orderUpdated($orders[$oid], ['responsible']);
            }
            $this->announce($facilityId, Ids::fromBinary($h->id), 'ACCEPTED', $from, $me);

            return $this->present(Ids::fromBinary($h->id)) + ['moved' => $moved, 'skipped' => $skipped];
        });
    }

    /** @return array<string, mixed> */
    public function reject(string $handoverId, ?string $reason): array
    {
        return $this->close($handoverId, 'REJECTED', 'to', $reason);
    }

    /** @return array<string, mixed> */
    public function cancel(string $handoverId): array
    {
        return $this->close($handoverId, 'CANCELLED', 'from', null);
    }

    /** @return array<string, mixed> */
    private function close(string $handoverId, string $status, string $side, ?string $reason): array
    {
        $me = Authz::staffId();

        return DB::transaction(function () use ($handoverId, $status, $side, $reason, $me) {
            $h = $this->lockHandover($handoverId);
            $facilityId = Ids::fromBinary($h->facility_unit_id);
            $party = Ids::fromBinary($side === 'to' ? $h->to_staff_id : $h->from_staff_id);
            if ($party !== $me && ! $this->mayManage($facilityId)) {
                throw ApiProblem::forbidden('permission_denied', $side === 'to' ? 'Only the receiving waiter can reject this.' : 'Only the waiter who started this can cancel it.');
            }
            $this->assertPending($h);
            DB::table('tab_handover')->where('id', $h->id)->update(['status' => $status, 'decided_by_staff_id' => Ids::toBinary($me), 'decided_at' => Fmt::now(), 'row_version' => $h->row_version + 1, 'note' => $reason ?? $h->note]);
            Audit::record('tab.handover.'.strtolower($status), 'TabHandover', Ids::fromBinary($h->id), old: ['status' => 'PENDING'], new: ['status' => $status, 'reason' => $reason], facilityUnitId: $facilityId);
            $this->announce($facilityId, Ids::fromBinary($h->id), $status, Ids::fromBinary($h->from_staff_id), Ids::fromBinary($h->to_staff_id));

            return $this->present(Ids::fromBinary($h->id));
        });
    }

    /**
     * Supervisor reassignment, effective immediately and recorded as a FORCED hand-over.
     *
     * @param  list<string>|null  $orderIds  null with $fromStaffId = all open orders of that waiter
     * @return array<string, mixed>
     */
    public function reassign(string $facilityId, string $toStaffId, ?string $fromStaffId, ?array $orderIds, string $reason): array
    {
        $facilityId = Ids::normalize($facilityId);
        $toStaffId = Ids::normalize($toStaffId);
        $this->requireFlow($facilityId);
        Authz::require('waiterflow.manage', $facilityId);
        $this->assertReceiver($toStaffId, $facilityId);
        $me = Authz::staffId();

        return DB::transaction(function () use ($facilityId, $toStaffId, $fromStaffId, $orderIds, $reason, $me) {
            $q = DB::table('order')->where('facility_unit_id', Ids::toBinary($facilityId))->whereIn('status', self::OPEN)->whereRaw('total - amount_paid > 0');
            if ($orderIds !== null) {
                $q->whereIn('id', array_map(fn ($i) => Ids::toBinary(Ids::normalize($i)), $orderIds));
            } elseif ($fromStaffId !== null) {
                $q->whereRaw('COALESCE(responsible_staff_id, created_by) = ?', [Ids::toBinary(Ids::normalize($fromStaffId))]);
            } else {
                throw ApiProblem::unprocessable('validation_failed', 'Say which orders or which waiter to reassign.');
            }
            $orders = $q->orderBy('id')->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw ApiProblem::conflict('nothing_to_hand_over', 'There are no open orders to reassign.');
            }
            $fac = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->first();
            $id = Ids::uuid7();
            $firstOwner = Ids::fromBinary($orders->first()->responsible_staff_id ?? $orders->first()->created_by);
            DB::table('tab_handover')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => $fac->organization_id, 'site_id' => $fac->site_id, 'facility_unit_id' => $fac->id,
                'from_staff_id' => Ids::toBinary($fromStaffId ? Ids::normalize($fromStaffId) : $firstOwner), 'to_staff_id' => Ids::toBinary($toStaffId),
                'status' => 'FORCED', 'note' => $reason, 'decided_by_staff_id' => Ids::toBinary($me), 'decided_at' => Fmt::now(),
                'expires_at' => Fmt::now(), 'device_id' => RequestContext::deviceId() ? Ids::toBinary(RequestContext::deviceId()) : null,
            ]);
            $tabs = [];
            $moved = [];
            foreach ($orders as $o) {
                $old = Ids::fromBinary($o->responsible_staff_id ?? $o->created_by);
                DB::table('order')->where('id', $o->id)->update(['responsible_staff_id' => Ids::toBinary($toStaffId), 'row_version' => $o->row_version + 1]);
                DB::table('tab_handover_item')->insert(['handover_id' => Ids::toBinary($id), 'order_id' => $o->id, 'tab_id' => $o->tab_id, 'moved' => 1]);
                Audit::record('order.reassign', 'Order', Ids::fromBinary($o->id), old: ['responsibleStaffId' => $old], new: ['responsibleStaffId' => $toStaffId, 'forcedBy' => $me, 'reason' => $reason], facilityUnitId: $facilityId);
                $moved[] = Ids::fromBinary($o->id);
                if ($o->tab_id !== null) {
                    $tabs[Ids::fromBinary($o->tab_id)] = $o->tab_id;
                }
            }
            foreach ($tabs as $tabBin) {
                DB::table('tab')->where('id', $tabBin)->where('status', 'OPEN')->update(['responsible_staff_id' => Ids::toBinary($toStaffId)]);
                DB::table('tab')->where('id', $tabBin)->increment('row_version');
            }
            Audit::record('tab.handover.forced', 'TabHandover', $id, new: ['by' => $me, 'to' => $toStaffId, 'orderIds' => $moved, 'reason' => $reason], facilityUnitId: $facilityId);
            foreach ($orders as $o) {
                $this->realtime->orderUpdated($o, ['responsible']);
            }
            $this->announce($facilityId, $id, 'FORCED', $firstOwner, $toStaffId);

            return $this->present($id) + ['moved' => $moved];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHandovers(string $facilityId, string $direction = 'all', ?string $status = 'PENDING'): array
    {
        $facilityId = Ids::normalize($facilityId);
        $this->requireWaiterRights($facilityId);
        $me = Authz::staffId();
        DB::transaction(fn () => $this->expireStale($facilityId));
        $q = DB::table('tab_handover')->where('facility_unit_id', Ids::toBinary($facilityId));
        $manage = $this->mayManage($facilityId);
        match ($direction) {
            'incoming' => $q->where('to_staff_id', Ids::toBinary($me)),
            'outgoing' => $q->where('from_staff_id', Ids::toBinary($me)),
            default => $manage ? null : $q->where(fn ($w) => $w->where('to_staff_id', Ids::toBinary($me))->orWhere('from_staff_id', Ids::toBinary($me))),
        };
        if ($status !== null && $status !== '') {
            $q->whereIn('status', array_map('strtoupper', explode(',', $status)));
        }

        return $q->orderByDesc('id')->limit(100)->get()->map(fn ($h) => $this->present(Ids::fromBinary($h->id)))->all();
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function present(string $id): array
    {
        $h = DB::table('tab_handover')->where('id', Ids::toBinary($id))->first() ?? throw ApiProblem::notFound('not_found', 'Hand-over not found.');
        $items = DB::table('tab_handover_item as i')->join('order as o', 'o.id', '=', 'i.order_id')->leftJoin('dining_table as dt', 'dt.id', '=', 'o.dining_table_id')
            ->where('i.handover_id', $h->id)->orderBy('o.order_number')
            ->get(['i.order_id', 'i.tab_id', 'i.moved', 'o.order_number', 'o.total', 'o.amount_paid', 'o.customer_name', 'dt.label as table_label']);
        $from = Ids::fromBinary($h->from_staff_id);
        $to = Ids::fromBinary($h->to_staff_id);
        $names = $this->staffNames([$from, $to]);

        return [
            'id' => Ids::fromBinary($h->id),
            'facilityId' => Ids::fromBinary($h->facility_unit_id),
            'status' => $h->status,
            'fromStaffId' => $from, 'fromName' => $names[$from] ?? null,
            'toStaffId' => $to, 'toName' => $names[$to] ?? null,
            'note' => $h->note,
            'createdAt' => Fmt::ts($h->created_at),
            'expiresAt' => Fmt::ts($h->expires_at),
            'decidedAt' => Fmt::ts($h->decided_at),
            'decidedByStaffId' => Fmt::u($h->decided_by_staff_id),
            'items' => $items->map(fn ($i) => [
                'orderId' => Ids::fromBinary($i->order_id), 'tabId' => Fmt::u($i->tab_id), 'number' => $i->order_number, 'tableLabel' => $i->table_label,
                'customerName' => $i->customer_name, 'balance' => Money::of($i->total)->sub(Money::of($i->amount_paid))->amount, 'moved' => (bool) $i->moved,
            ])->all(),
            'orderCount' => $items->count(),
            'balance' => $items->reduce(fn ($c, $i) => bcadd($c, Money::of($i->total)->sub(Money::of($i->amount_paid))->amount, 4), '0.0000'),
        ];
    }

    private function lockHandover(string $id): object
    {
        $h = DB::table('tab_handover')->where('id', Ids::toBinary(Ids::normalize($id)))->lockForUpdate()->first();
        if ($h === null || Ids::fromBinary($h->organization_id) !== Tenant::organizationId()) {
            throw ApiProblem::notFound('not_found', 'Hand-over not found.');
        }

        return $h;
    }

    private function assertPending(object $h): void
    {
        if ($h->status !== 'PENDING') {
            throw ApiProblem::conflict('handover_not_pending', "This hand-over is already {$h->status}.", ['status' => $h->status]);
        }
        if (strtotime($h->expires_at.' UTC') < time()) {
            DB::table('tab_handover')->where('id', $h->id)->update(['status' => 'EXPIRED', 'decided_at' => Fmt::now(), 'row_version' => $h->row_version + 1]);
            Audit::record('tab.handover.expired', 'TabHandover', Ids::fromBinary($h->id), old: ['status' => 'PENDING'], new: ['status' => 'EXPIRED'], facilityUnitId: Ids::fromBinary($h->facility_unit_id));
            throw ApiProblem::conflict('handover_expired', 'This hand-over expired. Ask the waiter to send it again.', ['status' => 'EXPIRED']);
        }
    }

    private function expireStale(string $facilityId): void
    {
        $stale = DB::table('tab_handover')->where('facility_unit_id', Ids::toBinary($facilityId))->where('status', 'PENDING')
            ->where('expires_at', '<', now('UTC')->format('Y-m-d H:i:s.u'))->lockForUpdate()->get();
        foreach ($stale as $h) {
            DB::table('tab_handover')->where('id', $h->id)->update(['status' => 'EXPIRED', 'decided_at' => Fmt::now(), 'row_version' => $h->row_version + 1]);
            Audit::record('tab.handover.expired', 'TabHandover', Ids::fromBinary($h->id), old: ['status' => 'PENDING'], new: ['status' => 'EXPIRED'], facilityUnitId: $facilityId);
        }
    }

    /** @param list<string>|null $orderIds */
    private function myOpenOrders(string $facilityId, string $staffId, ?array $orderIds, bool $lock)
    {
        $q = DB::table('order')->where('facility_unit_id', Ids::toBinary($facilityId))->whereIn('status', self::OPEN)
            ->whereRaw('COALESCE(responsible_staff_id, created_by) = ?', [Ids::toBinary($staffId)])->whereRaw('total - amount_paid > 0');
        if ($orderIds !== null) {
            $bins = array_map(fn ($i) => Ids::toBinary(Ids::normalize($i)), $orderIds);
            $q->whereIn('id', $bins);
            $found = (clone $q)->count();
            if ($found !== count(array_unique($bins))) {
                throw ApiProblem::unprocessable('validation_failed', 'Some of those orders are not yours or are already paid.', ['orderIds' => ['Not all orders can be handed over.']]);
            }
        }
        $lock && $q->lockForUpdate();

        return $q->orderBy('id')->get();
    }

    private function orderHasPendingMoney(string $orderBin): bool
    {
        return DB::table('payment_allocation as a')->join('payment as p', 'p.id', '=', 'a.payment_id')->where('a.order_id', $orderBin)
            ->whereIn('p.status', ['PENDING_CONFIRMATION', 'AUTHORIZING'])->exists();
    }

    /** @return array<string, true> orderId => true for orders inside a PENDING hand-over of the facility */
    private function pendingHandoverOrders(string $facilityId): array
    {
        return DB::table('tab_handover_item as i')->join('tab_handover as h', 'h.id', '=', 'i.handover_id')
            ->where('h.facility_unit_id', Ids::toBinary($facilityId))->where('h.status', 'PENDING')->pluck('i.order_id')
            ->mapWithKeys(fn ($b) => [Ids::fromBinary($b) => true])->all();
    }

    private function assertReceiver(string $staffId, string $facilityId): void
    {
        $s = DB::table('staff')->where('id', Ids::toBinary($staffId))->where('organization_id', Ids::toBinary(Tenant::organizationId()))->whereNull('deleted_at')->first();
        if ($s === null || ! $s->is_active) {
            throw ApiProblem::unprocessable('validation_failed', 'That staff member was not found or is not active.', ['toStaffId' => ['Unknown or inactive staff member.']]);
        }
        if (! Authz::can('order.create', $facilityId, $staffId)) {
            throw ApiProblem::unprocessable('validation_failed', 'That staff member cannot take orders at this facility.', ['toStaffId' => ['Not allowed to take orders here.']]);
        }
    }

    /** @param list<string> $ids @return array<string, string> */
    private function staffNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('staff')->whereIn('id', array_map(Ids::toBinary(...), $ids))->get(['id', 'first_name', 'last_name']) as $s) {
            $out[Ids::fromBinary($s->id)] = trim($s->first_name.' '.$s->last_name);
        }

        return $out;
    }

    private function announce(string $facilityId, string $handoverId, string $status, string $from, string $to): void
    {
        event(new TabHandoverUpdated([$this->realtime->facilityOrders($facilityId)], [
            'handoverId' => $handoverId, 'facilityId' => $facilityId, 'status' => $status, 'fromStaffId' => $from, 'toStaffId' => $to,
        ]));
    }
}
