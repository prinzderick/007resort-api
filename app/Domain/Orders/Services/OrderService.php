<?php

namespace App\Domain\Orders\Services;

use App\Domain\Catalog\Services\Pricing;
use App\Domain\Hospitality\Services\StationRouter;
use App\Domain\Hospitality\Services\TicketPresenter;
use App\Domain\Orders\Approvals\ApprovalService;
use App\Domain\Orders\Broadcast\PrepTicketCreated;
use App\Domain\Orders\Broadcast\PrepTicketUpdated;
use App\Domain\Orders\Events\OrderSent;
use App\Domain\Orders\Events\OrderSettled;
use App\Domain\Orders\Events\OrderVoided;
use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Order aggregate (architecture/08). Every mutation: one DB transaction, order row locked FOR UPDATE first (parent before
 * child; tickets are locked after the order everywhere), If-Match checked against the locked row_version, totals recomputed
 * server-side with decimal maths, Outbox row written in the same transaction, realtime hints dispatched after commit.
 *
 * Order state machine:
 *   DRAFT -> SENT -> IN_PREPARATION -> READY -> SERVED -> SETTLED      (SENT -> SERVED when nothing is routed)
 *   DRAFT|SENT|IN_PREPARATION|READY|SERVED -> VOIDED   (approval flow; PENDING_APPROVAL is a holding state)
 */
final class OrderService
{
    public const NOT_PAID_VOIDABLE = ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED'];

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Realtime $realtime,
        private readonly TableService $tables,
        private readonly TabService $tabs,
        private readonly Pricing $pricing,
        private readonly OperatingRules $rules,
        private readonly ApprovalService $approvals,
        private readonly StationRouter $router,
        private readonly TicketPresenter $tickets,
    ) {}

    // ---- reads ------------------------------------------------------------------------------------------------------

    public function find(string $orderId, bool $lock = false): object
    {
        $q = DB::table('order')->where('id', Ids::toBinary($orderId));
        $lock && $q->lockForUpdate();

        return $q->first() ?? throw ApiProblem::notFound('not_found', 'Order not found.');
    }

    /** @return array<string, mixed> */
    public function present(string $orderId): array
    {
        return $this->presenter->order($this->find($orderId));
    }

    // ---- create -----------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in
     * @return array{order: array<string, mixed>, replayed: bool}
     */
    public function create(array $in, string $requestHash): array
    {
        $facilityId = Ids::normalize($in['facilityId']);
        Authz::require('order.create', $facilityId);
        $clientId = isset($in['id']) ? Ids::normalize($in['id']) : null;
        if ($clientId && ($existing = DB::table('order')->where('id', Ids::toBinary($clientId))->first())) {
            return $this->replay($existing, $requestHash);
        }
        try {
            return DB::transaction(function () use ($in, $facilityId, $clientId, $requestHash) {
                $fac = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->where('is_active', 1)->whereNull('deleted_at')->first();
                if (! $fac) {
                    throw ApiProblem::notFound('not_found', 'Facility not found.');
                }
                $rules = $this->rules->forFacility($facilityId);
                $table = null;
                if (! empty($in['tableId'])) {
                    $table = $this->tables->find(Ids::normalize($in['tableId']), true);
                    if ($table->facility_unit_id !== $fac->id) {
                        throw ApiProblem::unprocessable('facility_mismatch', 'The table belongs to a different facility.');
                    }
                }
                $tabBin = null;
                if (! empty($in['tabId'])) {
                    $tab = $this->tabs->find(Ids::normalize($in['tabId']), true);
                    if ($tab->facility_unit_id !== $fac->id) {
                        throw ApiProblem::unprocessable('facility_mismatch', 'The tab belongs to a different facility.');
                    }
                    if ($tab->status !== 'OPEN') {
                        throw ApiProblem::conflict('order_state_invalid', 'The tab is not open.');
                    }
                    $tabBin = $tab->id;
                }
                $id = $clientId ?? Ids::uuid7();
                $channel = $in['channel'] ?? ($table ? 'DINE_IN' : 'COUNTER');
                DB::table('order')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => $fac->organization_id, 'site_id' => $fac->site_id, 'facility_unit_id' => $fac->id,
                    'order_number' => $this->nextNumber($fac), 'dining_table_id' => $table?->id, 'tab_id' => $tabBin, 'channel' => $channel,
                    'customer_name' => $in['customerName'] ?? null, 'status' => 'DRAFT', 'created_by' => Ids::toBinary(Authz::staffId()),
                    'device_id' => Fmt::b(RequestContext::deviceId()), 'client_created_at' => Fmt::clientTs($in['clientCreatedAt'] ?? null),
                    'client_request_hash' => $clientId ? $requestHash : null,
                ]);
                $order = $this->find($id, true);
                if ($table) {
                    $this->tables->occupyForOrder($table);
                }
                if ($tabBin === null && $rules['paymentTiming'] === OperatingRules::OPEN_TAB && $rules['allowOpenTabs']) {
                    DB::table('order')->where('id', $order->id)->update(['tab_id' => $this->tabs->ensureForOrder($order)]);
                }
                foreach ($in['lines'] ?? [] as $line) {
                    $this->insertLine($this->find($id), $line, hash('sha256', json_encode($line)));
                }
                $this->recalc($order->id);
                $fresh = $this->find($id);
                Outbox::record('OrderCreated', 'Order', $id, $this->outboxPayload($fresh), entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
                $this->realtime->orderUpdated($fresh, ['status', 'lines']);

                return ['order' => $this->presenter->order($fresh), 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && $clientId && ($existing = DB::table('order')->where('id', Ids::toBinary($clientId))->first())) {
                return $this->replay($existing, $requestHash);
            }
            throw $e;
        }
    }

    /** @return array{order: array<string, mixed>, replayed: bool} */
    private function replay(object $existing, string $requestHash): array
    {
        if ($existing->client_request_hash === null || ! hash_equals($existing->client_request_hash, $requestHash)) {
            throw ApiProblem::conflict('concurrency_conflict', 'That id is already used by a different order.');
        }

        return ['order' => $this->presenter->order($existing), 'replayed' => true];
    }

    private function nextNumber(object $facility): string
    {
        DB::statement('INSERT INTO order_number_counter (facility_unit_id, seq_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)', [$facility->id]);
        $n = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $facility->code) ?: 'ORD', 0, 6));

        return sprintf('%s-%06d', $prefix, $n);
    }

    // ---- lines ------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in  productId, quantity, notes?, id?, clientCreatedAt?
     * @return array{order: array<string, mixed>, replayed: bool}
     */
    public function addLine(string $orderId, array $in, ?int $ifMatch, string $requestHash): array
    {
        $order = $this->find($orderId);
        Authz::require('order.line.add', Ids::fromBinary($order->facility_unit_id));
        $lineId = isset($in['id']) ? Ids::normalize($in['id']) : null;
        if ($lineId && ($existing = DB::table('order_line')->where('id', Ids::toBinary($lineId))->first())) {
            return $this->lineReplay($existing, $order, $requestHash);
        }
        try {
            return DB::transaction(function () use ($orderId, $in, $ifMatch, $requestHash) {
                $order = $this->lockMutable($orderId, $ifMatch, 'DRAFT');
                $this->insertLine($order, $in, $requestHash);
                $this->recalc($order->id);
                $this->touch($order, 'OrderUpdated', ['lines'], bump: true);

                return ['order' => $this->present($orderId), 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && $lineId && ($existing = DB::table('order_line')->where('id', Ids::toBinary($lineId))->first())) {
                return $this->lineReplay($existing, $order, $requestHash);
            }
            throw $e;
        }
    }

    /** @return array{order: array<string, mixed>, replayed: bool} */
    private function lineReplay(object $existingLine, object $order, string $requestHash): array
    {
        if ($existingLine->order_id !== $order->id || $existingLine->client_request_hash === null || ! hash_equals($existingLine->client_request_hash, $requestHash)) {
            throw ApiProblem::conflict('concurrency_conflict', 'That id is already used by a different line.');
        }

        return ['order' => $this->presenter->order($this->find(Ids::fromBinary($order->id))), 'replayed' => true];
    }

    /** @param array<string, mixed> $in */
    private function insertLine(object $order, array $in, string $requestHash): void
    {
        $facilityId = Ids::fromBinary($order->facility_unit_id);
        $product = DB::table('product')->where('id', Ids::toBinary($in['productId']))->where('organization_id', $order->organization_id)->whereNull('deleted_at')->first();
        if (! $product) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown product.', ['productId' => ['Unknown product.']]);
        }
        $pf = DB::table('product_facility')->where('product_id', $product->id)->where('facility_unit_id', $order->facility_unit_id)->first();
        if (! $pf) {
            throw ApiProblem::unprocessable('capability_disabled', 'This facility does not sell that product.');
        }
        if (! $product->is_active || ! $pf->is_available) {
            throw ApiProblem::conflict('product_unavailable', "'{$product->name}' is not available right now.", ['productId' => Ids::fromBinary($product->id)]);
        }
        $price = $this->pricing->unitPrice(Ids::fromBinary($product->id), $facilityId);
        if ($price === null) {
            throw ApiProblem::unprocessable('price_missing', "'{$product->name}' has no price at this facility.");
        }
        $setting = $this->pricing->setting(Ids::fromBinary($order->organization_id));
        $rate = $this->pricing->rateFor($product, $setting);
        $qty = (int) $in['quantity'];
        $amounts = Pricing::line($price, $qty, $rate, $setting['pricesTaxInclusive']);
        $routeKind = $product->prep_route_id ? (string) DB::table('prep_route')->where('id', $product->prep_route_id)->value('kind') : 'NONE';
        $next = (int) DB::table('order_line')->where('order_id', $order->id)->max('line_no') + 1;
        DB::table('order_line')->insert([
            'id' => Ids::toBinary(isset($in['id']) ? Ids::normalize($in['id']) : Ids::uuid7()), 'order_id' => $order->id, 'product_id' => $product->id,
            'line_no' => $next, 'product_name' => $product->name, 'sku' => $product->sku, 'quantity' => $qty, 'unit_price' => $price,
            'tax_rate_percent' => $rate, 'tax_inclusive' => $setting['pricesTaxInclusive'] ? 1 : 0,
            'gross_amount' => $amounts['gross'], 'discount_amount' => $amounts['discount'], 'tax_amount' => $amounts['tax'], 'line_total' => $amounts['total'],
            'notes' => $in['notes'] ?? null, 'status' => 'PENDING', 'prep_route_id' => $product->prep_route_id, 'prep_route_kind' => $routeKind ?: 'NONE',
            'client_created_at' => Fmt::clientTs($in['clientCreatedAt'] ?? null), 'client_request_hash' => isset($in['id']) ? $requestHash : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function removeLine(string $orderId, string $lineId, ?int $ifMatch): array
    {
        $pre = $this->find($orderId);
        Authz::require('order.line.remove_unsent', Ids::fromBinary($pre->facility_unit_id));

        return DB::transaction(function () use ($orderId, $lineId, $ifMatch) {
            $order = $this->lockMutable($orderId, $ifMatch, 'DRAFT');
            $line = DB::table('order_line')->where('id', Ids::toBinary($lineId))->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $line || $line->status === 'REMOVED') {
                throw ApiProblem::notFound('not_found', 'Line not found on this order.');
            }
            if ($line->status !== 'PENDING') {
                throw ApiProblem::conflict('order_state_invalid', 'Only unsent lines can be removed.');
            }
            DB::table('order_line')->where('id', $line->id)->update(['status' => 'REMOVED', 'row_version' => $line->row_version + 1]);
            $this->recalc($order->id);
            $this->touch($order, 'OrderUpdated', ['lines'], bump: true);

            return $this->present($orderId);
        });
    }

    // ---- send / serve -----------------------------------------------------------------------------------------------

    /** @param list<string>|null $lineIds @return array<string, mixed> */
    public function send(string $orderId, ?int $ifMatch, ?array $lineIds = null): array
    {
        $pre = $this->find($orderId);
        Authz::require('order.send', Ids::fromBinary($pre->facility_unit_id));

        return DB::transaction(function () use ($orderId, $ifMatch, $lineIds) {
            $order = $this->lockMutable($orderId, $ifMatch, 'DRAFT');
            $facilityId = Ids::fromBinary($order->facility_unit_id);
            $lines = DB::table('order_line')->where('order_id', $order->id)->where('status', 'PENDING')->orderBy('line_no')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ApiProblem::unprocessable('validation_failed', 'The order has no lines to send.');
            }
            if ($lineIds !== null) {
                $want = collect($lineIds)->map(Ids::normalize(...))->sort()->values()->all();
                $have = $lines->map(fn ($l) => Ids::fromBinary($l->id))->sort()->values()->all();
                if ($want !== $have) {
                    throw ApiProblem::unprocessable('validation_failed', 'lineIds must list every pending line of the order (partial sends are not supported; use another order).');
                }
            }
            $rules = $this->rules->forFacility($facilityId);
            if ($rules['paymentTiming'] === OperatingRules::PAY_FIRST && bccomp($order->amount_paid, $order->total, 4) < 0) {
                throw ApiProblem::conflict('payment_state_invalid', 'This facility requires payment before the order is sent.', ['balanceDue' => Money::of($order->total)->sub(Money::of($order->amount_paid))->amount]);
            }
            $tabId = $order->tab_id;
            if ($tabId === null && $rules['paymentTiming'] === OperatingRules::OPEN_TAB && $rules['allowOpenTabs']) {
                $tabId = $this->tabs->ensureForOrder($order);
            }
            $now = Fmt::now();

            // route lines -> stations
            $byStation = [];
            foreach ($lines as $l) {
                $override = DB::table('product_facility')->where('product_id', $l->product_id)->where('facility_unit_id', $order->facility_unit_id)->value('kds_station_id');
                $station = $l->prep_route_kind === 'NONE' ? null : $this->router->stationFor($facilityId, $l->prep_route_id, $override);
                if ($station) {
                    $byStation[$station->id][] = [$l, $station];
                    DB::table('order_line')->where('id', $l->id)->update(['status' => 'ROUTED', 'station_id' => $station->id, 'sent_at' => $now, 'row_version' => $l->row_version + 1]);
                } else {
                    DB::table('order_line')->where('id', $l->id)->update(['status' => 'LOCKED', 'sent_at' => $now, 'row_version' => $l->row_version + 1]);
                }
            }

            $tableLabel = $order->dining_table_id ? DB::table('dining_table')->where('id', $order->dining_table_id)->value('label') : null;
            $created = [];
            foreach ($byStation as $stationBin => $entries) {
                $station = $entries[0][1];
                $ticketId = Ids::uuid7();
                DB::table('prep_ticket')->insert([
                    'id' => Ids::toBinary($ticketId), 'organization_id' => $order->organization_id, 'site_id' => $order->site_id, 'facility_unit_id' => $order->facility_unit_id,
                    'station_id' => $stationBin, 'order_id' => $order->id, 'ticket_number' => $this->nextTicketNumber($stationBin, $station->kind),
                    'order_number' => $order->order_number, 'table_label' => $tableLabel, 'waiter_staff_id' => $order->created_by, 'status' => 'NEW',
                ]);
                foreach ($entries as [$l]) {
                    DB::table('prep_ticket_item')->insert([
                        'id' => Ids::toBinary(Ids::uuid7()), 'prep_ticket_id' => Ids::toBinary($ticketId), 'order_line_id' => $l->id,
                        'name' => $l->product_name, 'quantity' => $l->quantity, 'notes' => $l->notes, 'status' => 'NEW',
                    ]);
                }
                $created[] = Ids::toBinary($ticketId);
            }

            DB::table('order')->where('id', $order->id)->update(['status' => 'SENT', 'sent_at' => $now, 'tab_id' => $tabId, 'row_version' => $order->row_version + 1]);
            $sentLines = $lines->map(fn ($l) => ['lineId' => Ids::fromBinary($l->id), 'productId' => Ids::fromBinary($l->product_id), 'qty' => (int) $l->quantity])->all();
            // dispatched inside the transaction: Inventory may throw insufficient_stock and roll the send back
            event(new OrderSent(Ids::fromBinary($order->id), $facilityId, $sentLines));

            $fresh = $this->find($orderId);
            Audit::record('order.send', 'Order', $orderId, old: ['status' => 'DRAFT'], new: ['status' => 'SENT', 'lines' => count($sentLines)], facilityUnitId: $facilityId);
            Outbox::record('OrderUpdated', 'Order', $orderId, $this->outboxPayload($fresh) + ['event' => 'sent'], entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
            foreach ($created as $tb) {
                $t = DB::table('prep_ticket')->where('id', $tb)->first();
                event(new PrepTicketCreated(['kds.station.'.Ids::fromBinary($t->station_id)], ['ticket' => $this->tickets->ticket($t)]));
            }
            $this->realtime->orderUpdated($fresh, ['status', 'lines']);

            return $this->presenter->order($fresh);
        });
    }

    private function nextTicketNumber(string $stationBin, string $kind): string
    {
        DB::statement('INSERT INTO prep_ticket_counter (station_id, seq_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)', [$stationBin]);
        $n = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('%s-%03d', $kind === 'BAR' ? 'B' : ($kind === 'DISPENSE' ? 'D' : 'K'), (($n - 1) % 999) + 1);
    }

    /** SERVED: READY (or SENT with nothing routed). Dispenses READY tickets. @return array<string, mixed> */
    public function serve(string $orderId, ?int $ifMatch): array
    {
        $pre = $this->find($orderId);
        Authz::require('order.serve', Ids::fromBinary($pre->facility_unit_id));

        return DB::transaction(function () use ($orderId, $ifMatch) {
            $order = $this->lockMutable($orderId, $ifMatch, ['SENT', 'IN_PREPARATION', 'READY']);
            $facilityId = Ids::fromBinary($order->facility_unit_id);
            $tickets = DB::table('prep_ticket')->where('order_id', $order->id)->where('status', '!=', 'CANCELLED')->lockForUpdate()->get();
            $notReady = $tickets->filter(fn ($t) => ! in_array($t->status, ['READY', 'DISPENSED'], true));
            if ($notReady->isNotEmpty()) {
                throw ApiProblem::conflict('order_state_invalid', 'Some items are still being prepared.', ['pendingTickets' => $notReady->map(fn ($t) => $t->ticket_number)->values()->all()]);
            }
            $now = Fmt::now();
            $staff = Ids::toBinary(Authz::staffId());
            foreach ($tickets->where('status', 'READY') as $t) {
                DB::table('prep_ticket')->where('id', $t->id)->update(['status' => 'DISPENSED', 'dispensed_at' => $now, 'dispensed_by' => $staff, 'row_version' => $t->row_version + 1]);
                DB::table('prep_ticket_item')->where('prep_ticket_id', $t->id)->where('status', '!=', 'CANCELLED')->update(['status' => 'DISPENSED']);
                $fresh = DB::table('prep_ticket')->where('id', $t->id)->first();
                event(new PrepTicketUpdated(['kds.station.'.Ids::fromBinary($t->station_id)], ['ticket' => $this->tickets->ticket($fresh), 'previousStatus' => 'READY']));
            }
            DB::table('order_line')->where('order_id', $order->id)->whereIn('status', ['LOCKED', 'ROUTED', 'ACCEPTED', 'IN_PROGRESS', 'READY'])->update(['status' => 'DISPENSED']);
            $paid = bccomp($order->amount_paid, $order->total, 4) >= 0 && bccomp($order->total, '0', 4) > 0;
            $upd = ['status' => 'SERVED', 'served_at' => $now, 'row_version' => $order->row_version + 1];
            if ($paid) { // pay-first: already covered, the serve completes the order
                $upd = ['status' => 'SETTLED', 'settled_at' => $order->settled_at ?? $now] + $upd;
            }
            DB::table('order')->where('id', $order->id)->update($upd);
            $fresh = $this->find($orderId);
            Outbox::record($paid ? 'OrderSettled' : 'OrderUpdated', 'Order', $orderId, $this->outboxPayload($fresh) + ['event' => 'served'], entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
            if ($paid) {
                event(new OrderSettled($orderId, $facilityId, Fmt::money($fresh->total), Fmt::money($fresh->amount_paid), Fmt::u($fresh->tab_id)));
                $this->tables->releaseIfIdle($fresh->dining_table_id, 'NEEDS_CLEANING');
            }
            $this->realtime->orderUpdated($fresh, ['status']);

            return $this->presenter->order($fresh);
        });
    }

    // ---- void -------------------------------------------------------------------------------------------------------

    /**
     * @return array{done: bool, order: array<string, mixed>, approval: ?array<string, mixed>}
     */
    public function void(string $orderId, string $reason, ?int $ifMatch, ?string $stepUpToken): array
    {
        $pre = $this->find($orderId);
        $facilityId = Ids::fromBinary($pre->facility_unit_id);

        return DB::transaction(function () use ($orderId, $reason, $ifMatch, $stepUpToken, $facilityId) {
            $order = $this->lock($orderId);
            Concurrency::assertVersion((int) $order->row_version, $ifMatch, 'order');
            $this->assertVoidable($order);
            $rules = $this->rules->forFacility($facilityId);
            // a draft that was never sent may be cancelled by anyone who may execute voids, unless the facility says otherwise
            $draftFree = $order->status === 'DRAFT' && ! in_array('order.void', $rules['requireApprovalFor'], true);
            $gate = $this->approvals->gate('order.void.execute', 'order.void.approve', $facilityId, $stepUpToken, $orderId, $draftFree);
            if ($gate['mode'] === 'EXECUTE') {
                $this->applyVoid($order, $reason, null, $gate['approvedBy']);

                return ['done' => true, 'order' => $this->present($orderId), 'approval' => null];
            }
            $approval = $this->approvals->request('order.void', 'Order', $orderId, $facilityId, 'order.void.approve', $reason,
                ['orderId' => $orderId, 'reason' => $reason], Fmt::money($order->total), "Void order {$order->order_number} (".number_format((float) $order->total, 2).')');
            DB::table('order')->where('id', $order->id)->update([
                'status_before_approval' => $order->status, 'status' => 'PENDING_APPROVAL', 'pending_approval_id' => $approval->id, 'row_version' => $order->row_version + 1,
            ]);
            $this->realtime->orderUpdated($order, ['status']);

            return ['done' => false, 'order' => $this->present($orderId), 'approval' => $this->approvals->present($approval)];
        });
    }

    private function assertVoidable(object $order): void
    {
        if ($order->status === 'PENDING_APPROVAL') {
            throw ApiProblem::conflict('approval_pending', 'This order already has a pending approval.', ['approvalId' => Fmt::u($order->pending_approval_id)]);
        }
        if (! in_array($order->status, self::NOT_PAID_VOIDABLE, true)) {
            throw ApiProblem::conflict('order_state_invalid', "An order that is {$order->status} cannot be voided.");
        }
        if (bccomp($order->amount_paid, '0', 4) > 0) {
            throw ApiProblem::conflict('payment_state_invalid', 'This order has payments; refund or reverse them first.');
        }
    }

    /** Executes the void. Caller holds the transaction + the order lock. */
    public function applyVoid(object $order, string $reason, ?string $approvalId, ?string $approvedBy): void
    {
        $orderId = Ids::fromBinary($order->id);
        $facilityId = Ids::fromBinary($order->facility_unit_id);
        $order = $this->find($orderId, true); // re-read (approval flow: status just restored)
        $lines = DB::table('order_line')->where('order_id', $order->id)->whereNotIn('status', ['REMOVED', 'VOIDED'])->orderBy('line_no')->lockForUpdate()->get();
        $staff = Ids::toBinary(Authz::staffId());
        $now = Fmt::now();
        $evLines = [];
        foreach ($lines as $l) {
            $sent = ! in_array($l->status, ['PENDING'], true);
            DB::table('line_void')->insert([
                'id' => Ids::toBinary(Ids::uuid7()), 'order_id' => $order->id, 'order_line_id' => $l->id, 'quantity' => $l->quantity, 'amount' => $l->line_total,
                'reason' => mb_substr($reason, 0, 255), 'approval_id' => Fmt::b($approvalId), 'voided_by' => $staff, 'approved_by' => Fmt::b($approvedBy),
            ]);
            DB::table('order_line')->where('id', $l->id)->update(['status' => 'VOIDED', 'row_version' => $l->row_version + 1]);
            $evLines[] = ['lineId' => Ids::fromBinary($l->id), 'productId' => Ids::fromBinary($l->product_id), 'qty' => (int) $l->quantity, 'sent' => $sent];
        }
        // cancel prep tickets (parent order already locked)
        $tickets = DB::table('prep_ticket')->where('order_id', $order->id)->whereNotIn('status', ['CANCELLED', 'DISPENSED'])->lockForUpdate()->get();
        foreach ($tickets as $t) {
            DB::table('prep_ticket')->where('id', $t->id)->update(['status' => 'CANCELLED', 'cancelled_at' => $now, 'row_version' => $t->row_version + 1]);
            DB::table('prep_ticket_item')->where('prep_ticket_id', $t->id)->update(['status' => 'CANCELLED']);
            $fresh = DB::table('prep_ticket')->where('id', $t->id)->first();
            event(new PrepTicketUpdated(['kds.station.'.Ids::fromBinary($t->station_id)], ['ticket' => $this->tickets->ticket($fresh), 'previousStatus' => $t->status]));
        }
        $oldStatus = $order->status;
        DB::table('order')->where('id', $order->id)->update([
            'status' => 'VOIDED', 'void_reason' => mb_substr($reason, 0, 255), 'voided_at' => $now, 'pending_approval_id' => null,
            'status_before_approval' => null, 'row_version' => $order->row_version + 1,
        ]);
        $fresh = $this->find($orderId);
        Audit::record('order.void', 'Order', $orderId,
            old: ['status' => $oldStatus, 'total' => Fmt::money($order->total)], new: ['status' => 'VOIDED', 'reason' => $reason, 'approvedBy' => $approvedBy],
            facilityUnitId: $facilityId, approvalId: $approvalId);
        Outbox::record('OrderVoided', 'Order', $orderId, $this->outboxPayload($fresh) + ['reason' => $reason, 'approvalId' => $approvalId], entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
        event(new OrderVoided($orderId, $facilityId, $evLines, $reason, $approvalId));
        $this->tables->releaseIfIdle($fresh->dining_table_id, 'FREE');
        $this->realtime->orderUpdated($fresh, ['status', 'lines']);
    }

    // ---- adjustments (discount / price override / comp) -------------------------------------------------------------

    /**
     * @param  array{kind: string, value: string, reason: string}  $in
     * @return array{done: bool, order: array<string, mixed>, approval: ?array<string, mixed>}
     */
    public function adjust(string $orderId, string $lineId, array $in, ?int $ifMatch, ?string $stepUpToken): array
    {
        $pre = $this->find($orderId);
        $facilityId = Ids::fromBinary($pre->facility_unit_id);
        [$exec, $approve] = match ($in['kind']) {
            'PRICE_OVERRIDE' => ['order.price_override.execute', 'order.price_override.approve'],
            'COMP' => ['order.comp.execute', 'order.comp.approve'],
            default => ['order.discount.execute', 'order.discount.approve'],
        };

        return DB::transaction(function () use ($orderId, $lineId, $in, $ifMatch, $stepUpToken, $facilityId, $exec, $approve) {
            $order = $this->lock($orderId);
            Concurrency::assertVersion((int) $order->row_version, $ifMatch, 'order');
            if ($order->status === 'PENDING_APPROVAL') {
                throw ApiProblem::conflict('approval_pending', 'This order has a pending approval.');
            }
            if (in_array($order->status, ['SETTLED', 'VOIDED'], true)) {
                throw ApiProblem::conflict('order_state_invalid', "An order that is {$order->status} cannot be adjusted.");
            }
            $line = DB::table('order_line')->where('id', Ids::toBinary($lineId))->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $line || in_array($line->status, ['REMOVED', 'VOIDED'], true)) {
                throw ApiProblem::notFound('not_found', 'Line not found on this order.');
            }
            $amount = $this->adjustmentAmount($line, $in['kind'], $in['value']);
            $rules = $this->rules->forFacility($facilityId);
            $small = $in['kind'] !== 'COMP' && $in['kind'] !== 'PRICE_OVERRIDE'
                && bccomp($amount, $rules['approvalThresholdAmount'], 4) <= 0 && ! in_array('order.discount', $rules['requireApprovalFor'], true);
            $gate = $this->approvals->gate($exec, $approve, $facilityId, $stepUpToken, $orderId, $small);
            $adjId = Ids::uuid7();
            DB::table('line_adjustment')->insert([
                'id' => Ids::toBinary($adjId), 'order_line_id' => $line->id, 'kind' => $in['kind'], 'value' => $in['value'], 'reason' => mb_substr($in['reason'], 0, 255),
                'amount' => $amount, 'status' => 'PENDING_APPROVAL', 'requested_by' => Ids::toBinary(Authz::staffId()),
            ]);
            if ($gate['mode'] === 'EXECUTE') {
                $this->applyAdjustment($orderId, $adjId, null, $gate['approvedBy']);

                return ['done' => true, 'order' => $this->present($orderId), 'approval' => null];
            }
            $approval = $this->approvals->request('order.adjust', 'Order', $orderId, $facilityId, $approve, $in['reason'],
                ['orderId' => $orderId, 'lineId' => $lineId, 'adjustmentId' => $adjId, 'kind' => $in['kind'], 'value' => $in['value']],
                $amount, "{$in['kind']} {$in['value']} on {$line->product_name} ({$order->order_number})");
            DB::table('line_adjustment')->where('id', Ids::toBinary($adjId))->update(['approval_id' => $approval->id]);

            return ['done' => false, 'order' => $this->present($orderId), 'approval' => $this->approvals->present($approval)];
        });
    }

    /** Discount/override/comp value in NGN for a line, validated. */
    private function adjustmentAmount(object $line, string $kind, string $value): string
    {
        $gross = Money::of($line->unit_price)->mul((int) $line->quantity);
        switch ($kind) {
            case 'DISCOUNT_PERCENT':
                if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0 || bccomp($value, '100', 2) > 0) {
                    throw ApiProblem::unprocessable('validation_failed', 'A percentage discount must be between 0 and 100.');
                }

                return $gross->percent($value)->amount;
            case 'DISCOUNT_AMOUNT':
                if (! Money::isValid($value) || bccomp($value, '0', 4) <= 0 || bccomp($value, $gross->amount, 4) > 0) {
                    throw ApiProblem::unprocessable('validation_failed', 'The discount must be positive and not exceed the line amount.');
                }

                return Money::normalize($value);
            case 'PRICE_OVERRIDE':
                if (! Money::isValid($value) || bccomp($value, '0', 4) < 0) {
                    throw ApiProblem::unprocessable('validation_failed', 'The override price must be a non-negative decimal string.');
                }

                return Money::of($line->unit_price)->sub(Money::of($value))->mul((int) $line->quantity)->amount; // signed: >0 cheaper

            default: // COMP
                return $gross->amount;
        }
    }

    /** Marks an adjustment APPLIED, recomputes the order, audits. Caller holds the transaction. */
    public function applyAdjustment(string $orderId, string $adjustmentId, ?string $approvalId, ?string $approvedBy): void
    {
        $order = $this->find($orderId, true);
        $adj = DB::table('line_adjustment')->where('id', Ids::toBinary($adjustmentId))->lockForUpdate()->first();
        if (! $adj || $adj->status !== 'PENDING_APPROVAL') {
            throw ApiProblem::conflict('order_state_invalid', 'The adjustment is no longer pending.');
        }
        if (in_array($order->status, ['SETTLED', 'VOIDED'], true)) {
            throw ApiProblem::conflict('order_state_invalid', "The order is {$order->status}; the adjustment can no longer be applied.");
        }
        DB::table('line_adjustment')->where('id', $adj->id)->update([
            'status' => 'APPLIED', 'applied_by' => Fmt::b($approvedBy ?? Authz::staffId()), 'decided_at' => Fmt::now(), 'approval_id' => $approvalId !== null ? Ids::toBinary($approvalId) : $adj->approval_id,
        ]);
        $this->recalc($order->id);
        $facilityId = Ids::fromBinary($order->facility_unit_id);
        DB::table('order')->where('id', $order->id)->update(['row_version' => $order->row_version + 1]);
        $fresh = $this->find($orderId);
        Audit::record('order.line.adjust', 'Order', $orderId,
            old: ['total' => Fmt::money($order->total)], new: ['total' => Fmt::money($fresh->total), 'kind' => $adj->kind, 'value' => $adj->value, 'reason' => $adj->reason, 'lineId' => Ids::fromBinary($adj->order_line_id), 'approvedBy' => $approvedBy],
            facilityUnitId: $facilityId, approvalId: $approvalId ?? Fmt::u($adj->approval_id));
        Outbox::record('OrderUpdated', 'Order', $orderId, $this->outboxPayload($fresh) + ['event' => 'adjusted'], entityVersion: (int) $fresh->row_version, facilityId: $facilityId);
        $this->realtime->orderUpdated($fresh, ['lines', 'totals']);
    }

    public function rejectAdjustment(string $adjustmentId): void
    {
        DB::table('line_adjustment')->where('id', Ids::toBinary($adjustmentId))->where('status', 'PENDING_APPROVAL')->update(['status' => 'REJECTED', 'decided_at' => Fmt::now()]);
    }

    // ---- approval helpers (used by handlers) ------------------------------------------------------------------------

    /** Put a PENDING_APPROVAL order back to the state it had (requires the order lock). */
    public function restoreFromApproval(string $orderId): object
    {
        $o = $this->find($orderId, true);
        if ($o->status === 'PENDING_APPROVAL') {
            DB::table('order')->where('id', $o->id)->update([
                'status' => $o->status_before_approval ?? 'DRAFT', 'status_before_approval' => null, 'pending_approval_id' => null, 'row_version' => $o->row_version + 1,
            ]);
            $o = $this->find($orderId);
            $this->realtime->orderUpdated($o, ['status']);
        }

        return $o;
    }

    // ---- shared internals -------------------------------------------------------------------------------------------

    public function lock(string $orderId): object
    {
        return $this->find($orderId, true);
    }

    /**
     * Lock the order, check If-Match and that its status allows the mutation.
     *
     * @param  string|list<string>  $allowed
     */
    private function lockMutable(string $orderId, ?int $ifMatch, string|array $allowed): object
    {
        $order = $this->lock($orderId);
        Concurrency::assertVersion((int) $order->row_version, $ifMatch, 'order');
        if ($order->status === 'PENDING_APPROVAL') {
            throw ApiProblem::conflict('approval_pending', 'This order has a pending approval; it cannot be changed until it is decided.', ['approvalId' => Fmt::u($order->pending_approval_id)]);
        }
        if (! in_array($order->status, (array) $allowed, true)) {
            throw ApiProblem::conflict('order_state_invalid', "The order is {$order->status}; this action needs ".implode('/', (array) $allowed).'.', ['status' => $order->status]);
        }

        return $order;
    }

    /** Bump row_version, write the outbox row and the realtime hint for a simple change. */
    private function touch(object $order, string $outboxType, array $changed, bool $bump): void
    {
        if ($bump) {
            DB::table('order')->where('id', $order->id)->update(['row_version' => $order->row_version + 1]);
        }
        $fresh = DB::table('order')->where('id', $order->id)->first();
        Outbox::record($outboxType, 'Order', Ids::fromBinary($order->id), $this->outboxPayload($fresh), entityVersion: (int) $fresh->row_version, facilityId: Ids::fromBinary($order->facility_unit_id));
        $this->realtime->orderUpdated($fresh, $changed);
    }

    /**
     * Recompute every live line and the order totals from snapshots + APPLIED adjustments.
     * subtotal = sum(gross) (pre-discount); total = sum(line total); tax inclusive prices => total = subtotal - discounts.
     */
    public function recalc(string $orderBin): void
    {
        $lines = DB::table('order_line')->where('order_id', $orderBin)->whereNotIn('status', ['REMOVED', 'VOIDED'])->get();
        $applied = $lines->isEmpty() ? collect() : DB::table('line_adjustment')->whereIn('order_line_id', $lines->pluck('id')->all())->where('status', 'APPLIED')->orderBy('created_at')->orderBy('id')->get()->groupBy(fn ($a) => bin2hex($a->order_line_id));
        $sub = $disc = $tax = $tot = Money::zero();
        foreach ($lines as $l) {
            $unit = $l->unit_price;
            $discount = '0';
            $comp = false;
            foreach ($applied[bin2hex($l->id)] ?? [] as $a) {
                if ($a->kind === 'PRICE_OVERRIDE') {
                    $unit = Money::normalize($a->value);
                } elseif ($a->kind === 'COMP') {
                    $comp = true;
                }
            }
            $gross = Money::of($unit)->mul((int) $l->quantity);
            foreach ($applied[bin2hex($l->id)] ?? [] as $a) {
                if ($a->kind === 'DISCOUNT_PERCENT') {
                    $discount = bcadd($discount, $gross->percent($a->value)->amount, 4);
                } elseif ($a->kind === 'DISCOUNT_AMOUNT') {
                    $discount = bcadd($discount, Money::normalize($a->value), 4);
                }
            }
            if ($comp) {
                $discount = $gross->amount;
            }
            $r = Pricing::line($unit, (int) $l->quantity, $l->tax_rate_percent, (bool) $l->tax_inclusive, $discount);
            DB::table('order_line')->where('id', $l->id)->update(['gross_amount' => $r['gross'], 'discount_amount' => $r['discount'], 'tax_amount' => $r['tax'], 'line_total' => $r['total']]);
            $sub = $sub->add(Money::of($r['gross']));
            $disc = $disc->add(Money::of($r['discount']));
            $tax = $tax->add(Money::of($r['tax']));
            $tot = $tot->add(Money::of($r['total']));
        }
        DB::table('order')->where('id', $orderBin)->update(['subtotal' => $sub->amount, 'discount_total' => $disc->amount, 'tax_total' => $tax->amount, 'total' => $tot->amount]);
    }

    /** @return array<string, mixed> */
    public function outboxPayload(object $o): array
    {
        return [
            'orderId' => Ids::fromBinary($o->id), 'orderNumber' => $o->order_number, 'facilityId' => Ids::fromBinary($o->facility_unit_id),
            'tableId' => Fmt::u($o->dining_table_id), 'tabId' => Fmt::u($o->tab_id), 'status' => $o->status, 'channel' => $o->channel,
            'subtotal' => Fmt::money($o->subtotal), 'discountTotal' => Fmt::money($o->discount_total), 'taxTotal' => Fmt::money($o->tax_total),
            'total' => Fmt::money($o->total), 'amountPaid' => Fmt::money($o->amount_paid), 'currency' => $o->currency,
            'createdByStaffId' => Ids::fromBinary($o->created_by),
            'lineCount' => (int) DB::table('order_line')->where('order_id', $o->id)->whereNotIn('status', ['REMOVED', 'VOIDED'])->count(),
        ];
    }
}
