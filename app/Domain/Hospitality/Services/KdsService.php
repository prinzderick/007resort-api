<?php

namespace App\Domain\Hospitality\Services;

use App\Domain\Orders\Broadcast\PrepTicketUpdated;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Orders\Services\Realtime;
use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KDS: stations, ticket board and the prep-ticket state machine.
 *
 *   NEW -> ACCEPTED -> IN_PREPARATION(IN_PROGRESS) -> READY -> DISPENSED      (NEW -> IN_PROGRESS and ACCEPTED -> READY may skip)
 *   NEW|ACCEPTED|IN_PROGRESS|READY -> CANCELLED   (only via order void, never by the KDS)
 * Order follows the tickets: any ticket started => IN_PREPARATION; every ticket READY/DISPENSED => READY (+ `order.ready`).
 */
final class KdsService
{
    /** @var array<string, list<string>> */
    public const LEGAL = [
        'NEW' => ['ACCEPTED', 'IN_PROGRESS'],
        'ACCEPTED' => ['IN_PROGRESS', 'READY'],
        'IN_PROGRESS' => ['READY'],
        'READY' => ['DISPENSED'],
    ];

    public function __construct(private readonly TicketPresenter $tickets, private readonly Realtime $realtime, private readonly OrderService $orders) {}

    /** @return array<string, mixed> */
    public function stations(Request $request): array
    {
        $q = DB::table('kds_station')->where('organization_id', Ids::toBinary(Tenant::organizationId()))->where('is_active', 1);
        if ($fid = $request->query('facilityId')) {
            // stations located at the facility PLUS stations elsewhere that prepare its orders (Restaurant -> Main Kitchen)
            $q->where(fn ($w) => $w->where('facility_unit_id', Ids::toBinary($fid))
                ->orWhereIn('id', DB::table('prep_route_station')->where('facility_unit_id', Ids::toBinary($fid))->select('kds_station_id')));
        }
        $page = CursorPage::paginate($q, $request);
        $can = [];
        $items = $page->items->filter(fn ($s) => $can[bin2hex($s->facility_unit_id)] ??= Authz::can('prep_ticket.view', Ids::fromBinary($s->facility_unit_id)))->values();

        return ['items' => $items->map(fn ($s) => [
            'id' => Ids::fromBinary($s->id), 'facilityId' => Ids::fromBinary($s->facility_unit_id), 'name' => $s->name, 'kind' => $s->kind, 'active' => (bool) $s->is_active,
        ])->all(), 'nextCursor' => $page->toArray()['nextCursor']];
    }

    /** @return array<string, mixed> */
    public function board(Request $request, string $stationId): array
    {
        $st = DB::table('kds_station')->where('id', Ids::toBinary($stationId))->first() ?? throw ApiProblem::notFound('not_found', 'Station not found.');
        Authz::require('prep_ticket.view', Ids::fromBinary($st->facility_unit_id));
        $statuses = array_filter(array_map('strtoupper', explode(',', (string) (((array) $request->query('filter', []))['status'] ?? 'NEW,ACCEPTED,IN_PROGRESS,READY'))));
        $q = DB::table('prep_ticket')->where('station_id', $st->id)->whereIn('status', $statuses);
        if ($since = Fmt::clientTs($request->query('updatedSince'))) {
            $q->where('updated_at', '>=', $since);
        }
        $page = CursorPage::paginate($q, $request);

        return ['items' => $page->items->map(fn ($t) => $this->tickets->ticket($t))->all(), 'nextCursor' => $page->toArray()['nextCursor']];
    }

    public function get(string $ticketId): object
    {
        $t = DB::table('prep_ticket')->where('id', Ids::toBinary($ticketId))->first() ?? throw ApiProblem::notFound('not_found', 'Prep ticket not found.');
        $this->authorize('prep_ticket.view', $t);

        return $t;
    }

    /** A ticket is visible/workable to staff scoped to the station's facility (the kitchen) OR to the ordering facility. */
    private function authorize(string $permission, object $ticket): void
    {
        $order = Ids::fromBinary($ticket->facility_unit_id);
        $stationFacility = DB::table('kds_station')->where('id', $ticket->station_id)->value('facility_unit_id');
        if (Authz::can($permission, $order) || ($stationFacility !== null && Authz::can($permission, Ids::fromBinary($stationFacility)))) {
            return;
        }
        throw ApiProblem::permissionDenied($permission);
    }

    /** @return array<string, mixed> ticket JSON */
    public function transition(string $ticketId, string $to, ?int $ifMatch, ?string $note = null): array
    {
        $pre = DB::table('prep_ticket')->where('id', Ids::toBinary($ticketId))->first() ?? throw ApiProblem::notFound('not_found', 'Prep ticket not found.');
        $this->authorize('prep_ticket.transition', $pre);

        return DB::transaction(function () use ($to, $ifMatch, $pre) {
            $order = $this->orders->lock(Ids::fromBinary($pre->order_id)); // parent first
            $t = DB::table('prep_ticket')->where('id', $pre->id)->lockForUpdate()->first();
            Concurrency::assertVersion((int) $t->row_version, $ifMatch, 'prep ticket');
            if (! in_array($to, self::LEGAL[$t->status] ?? [], true)) {
                throw ApiProblem::conflict('order_state_invalid', "A ticket that is {$t->status} cannot move to {$to}.", ['currentStatus' => $t->status, 'allowed' => self::LEGAL[$t->status] ?? []]);
            }
            $now = Fmt::now();
            $staff = Ids::toBinary(Authz::staffId());
            $upd = ['status' => $to, 'row_version' => $t->row_version + 1];
            if (in_array($to, ['ACCEPTED', 'IN_PROGRESS'], true) && $t->accepted_at === null) {
                $upd += ['accepted_at' => $now, 'accepted_by' => $staff];
            }
            if ($to === 'IN_PROGRESS') {
                $upd['started_at'] = $now;
            }
            if ($to === 'READY') {
                $upd += ['ready_at' => $now, 'ready_by' => $staff];
                if ($t->accepted_at === null) {
                    $upd += ['accepted_at' => $now, 'accepted_by' => $staff];
                }
            }
            if ($to === 'DISPENSED') {
                $upd += ['dispensed_at' => $now, 'dispensed_by' => $staff];
            }
            DB::table('prep_ticket')->where('id', $t->id)->update($upd);
            DB::table('prep_ticket_item')->where('prep_ticket_id', $t->id)->whereNot('status', 'CANCELLED')->update(['status' => $to]);
            DB::table('order_line')->whereIn('id', DB::table('prep_ticket_item')->where('prep_ticket_id', $t->id)->whereNot('status', 'CANCELLED')->select('order_line_id'))
                ->whereNotIn('status', ['VOIDED', 'REMOVED'])->update(['status' => $to]);

            $this->syncOrder($order);

            $fresh = DB::table('prep_ticket')->where('id', $t->id)->first();
            event(new PrepTicketUpdated(['kds.station.'.Ids::fromBinary($t->station_id)], ['ticket' => $this->tickets->ticket($fresh), 'previousStatus' => $t->status]));

            return $this->tickets->ticket($fresh);
        });
    }

    /** Derive the order status from its tickets. Order row is already locked. */
    private function syncOrder(object $order): void
    {
        $tickets = DB::table('prep_ticket')->where('order_id', $order->id)->where('status', '!=', 'CANCELLED')->get(['status', 'station_id']);
        if ($tickets->isEmpty()) {
            return;
        }
        $holding = $order->status === 'PENDING_APPROVAL';
        $current = $holding ? $order->status_before_approval : $order->status;
        if (! in_array($current, ['SENT', 'IN_PREPARATION', 'READY'], true)) {
            return;
        }
        $allDone = $tickets->every(fn ($t) => in_array($t->status, ['READY', 'DISPENSED'], true));
        $target = $allDone ? 'READY' : ($tickets->contains(fn ($t) => $t->status !== 'NEW') ? 'IN_PREPARATION' : $current);
        if ($target === $current) {
            return;
        }
        DB::table('order')->where('id', $order->id)->update($holding
            ? ['status_before_approval' => $target, 'row_version' => $order->row_version + 1]
            : ['status' => $target, 'row_version' => $order->row_version + 1]);
        $fresh = DB::table('order')->where('id', $order->id)->first();
        Outbox::record('OrderUpdated', 'Order', Ids::fromBinary($order->id), $this->orders->outboxPayload($fresh) + ['event' => 'prep'], entityVersion: (int) $fresh->row_version, facilityId: Ids::fromBinary($order->facility_unit_id));
        $this->realtime->orderUpdated($fresh, ['status']);
        if ($target === 'READY') {
            $this->realtime->orderReady($fresh, $tickets->pluck('station_id')->unique()->map(fn ($b) => Ids::fromBinary($b))->values()->all());
        }
    }
}
