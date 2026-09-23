<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Support\AllocationPlanner;
use App\Domain\Payments\Support\FacilityRules;
use App\Domain\Payments\Support\Fmt;
use App\Domain\Payments\Support\Ledger;
use App\Domain\Payments\Support\Tenantless;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * In-person payments: `POST /payments` and `POST /tabs/{id}/settle`.
 *
 * Concurrency model (architecture/07 §4): every settlement takes the tab row lock (if any) and then the order row locks
 * (ascending id) with SELECT ... FOR UPDATE as its FIRST statements, and only then reads balances. Two concurrent
 * settlements of the same order/tab therefore serialise; the loser re-reads the updated balance and gets
 * `409 balance_changed` - money is never over-allocated. Idempotency-Key replays and client tender ids (offline) are
 * handled on top (route middleware + payment.id primary key).
 */
class PaymentService
{
    private const PAYABLE = ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED'];

    public function __construct(
        private readonly OrderPort $orders,
        private readonly FacilityRules $rules,
        private readonly PermissionChecker $permissions,
        private readonly PaymentPresenter $presenter,
        private readonly PaymentEffects $effects,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * @param  array<string, mixed>  $in  validated request (see PaymentController::store)
     * @return array{body: array<string, mixed>, replayed: bool}
     */
    public function create(array $in, string $staffId): array
    {
        return $this->run($in + ['tabId' => null], $staffId, false);
    }

    /**
     * @param  array<string, mixed>  $in  tenders + optional cashSessionId
     * @return array{body: array<string, mixed>, replayed: bool}
     */
    public function settleTab(string $tabId, array $in, string $staffId): array
    {
        return $this->run(['tabId' => $tabId, 'allocations' => [], 'facilityId' => null, 'customerName' => null] + $in, $staffId, true);
    }

    /** @return array{body: array<string, mixed>, replayed: bool} */
    private function run(array $in, string $staffId, bool $tabMode): array
    {
        return DB::transaction(function () use ($in, $staffId, $tabMode) {
            // ---- 1. LOCK FIRST (tab, then orders by id). No other read may precede these. -----------------------------
            $tab = null;
            if ($in['tabId'] !== null) {
                $tab = $this->orders->lockTab($in['tabId']) ?? throw ApiProblem::notFound('not_found', 'Tab not found.');
            }
            $requested = $tabMode ? $tab['orderIds'] : array_map(fn ($a) => $a['orderId'], $in['allocations']);
            $orders = $this->orders->lockOrders($tab !== null ? array_merge($requested, $tab['orderIds']) : $requested);
            foreach ($requested as $oid) {
                if (! isset($orders[Ids::normalize($oid)])) {
                    throw ApiProblem::notFound('not_found', 'Order not found.');
                }
            }
            $facilityId = $tabMode ? $tab['facilityId'] : Ids::normalize($in['facilityId']);

            // ---- 2. Authorisation that needs the facility (post-lock reads are fresh). ----------------------------------
            $scope = Scope::facility($facilityId);
            $need = $tabMode ? 'order.settle' : 'payment.take';
            if (! $this->permissions->can($staffId, $need, $scope)) {
                throw ApiProblem::permissionDenied($need);
            }
            if (count($in['tenders']) > 1 && ! $this->permissions->can($staffId, 'payment.split', $scope)) {
                throw ApiProblem::permissionDenied('payment.split');
            }

            // ---- 3. Client-supplied tender ids (offline): replay the original result, or reject a changed body. ----------
            if (($replay = $this->replayIfKnown($in, $facilityId, $orders)) !== null) {
                return ['body' => $replay, 'replayed' => true];
            }

            // ---- 4. Balances (fresh: we hold the order locks) and allocation validation. -------------------------------
            $paidBefore = Ledger::paidByOrder(array_keys($orders));
            if ($tabMode) {
                [$allocations, $tenders] = $this->tabAllocations($orders, $paidBefore, $in['tenders']);
            } else {
                $allocations = $this->validatedAllocations($in['allocations'], $orders, $paidBefore, $facilityId, $tab);
                $tenders = $in['tenders'];
                $sumT = '0';
                foreach ($tenders as $t) {
                    $sumT = bcadd($sumT, Money::normalize($t['amount']), 4);
                }
                $sumA = '0';
                foreach ($allocations as $a) {
                    $sumA = bcadd($sumA, $a['amount'], 4);
                }
                if (bccomp($sumT, $sumA, 4) !== 0) {
                    throw ApiProblem::unprocessable('amount_mismatch', "Sum of tenders ({$sumT}) must equal the sum of allocations ({$sumA}).");
                }
            }
            $tenders = $this->normalizedTenders($tenders);

            // ---- 5. Cash session (shared lock: a concurrent close waits for us; after close we get cash_session_required). --
            $hasCash = (bool) array_filter($tenders, fn ($t) => $t['tenderType'] === 'CASH');
            $session = $this->cashSessionFor($in['cashSessionId'] ?? null, $facilityId, $staffId, $hasCash);

            // ---- 6. Write: receipt -> payments -> allocations -> orders -> audit/outbox/event. -------------------------
            $groupId = Ids::uuid7();
            ['org' => $orgId, 'site' => $siteId] = Tenantless::facility($facilityId);
            $now = Fmt::now();

            $plans = AllocationPlanner::plan($allocations, array_map(fn ($t) => $t['amount'], $tenders));
            $allocatedNow = [];
            foreach ($allocations as $a) {
                $allocatedNow[$a['orderId']] = $a['amount'];
            }
            $amountPaid = array_reduce($allocations, fn ($c, $a) => bcadd($c, $a['amount'], 4), '0.0000');
            $balanceDue = '0.0000';
            $receiptOrders = [];
            foreach ($allocatedNow as $oid => $amt) {
                $receiptOrders[] = $orders[$oid];
                $balanceDue = bcadd($balanceDue, bcsub($orders[$oid]['total'], bcadd($paidBefore[$oid], $amt, 4), 4), 4);
            }
            $deviceId = RequestContext::deviceId();
            $receipt = $this->receipts->issue([
                'organizationId' => $orgId, 'siteId' => $siteId, 'facilityId' => $facilityId, 'groupId' => $groupId,
                'staffId' => $staffId, 'deviceId' => $deviceId, 'orders' => $receiptOrders,
                'details' => $this->orders->details(array_keys($allocatedNow)),
                'tenders' => array_map(fn ($t) => [
                    'tenderType' => $t['tenderType'], 'amount' => $t['amount'], 'reference' => $t['reference'],
                    'tendered' => $t['tendered'], 'changeGiven' => $t['changeGiven'],
                ], $tenders),
                'amountPaid' => $amountPaid, 'balanceDue' => $balanceDue,
            ]);

            $paymentRows = [];
            foreach ($tenders as $i => $t) {
                $id = $t['id'] ?? Ids::uuid7();
                $row = [
                    'id' => Ids::toBinary($id),
                    'organization_id' => Ids::toBinary($orgId),
                    'site_id' => Ids::toBinary($siteId),
                    'facility_unit_id' => Ids::toBinary($facilityId),
                    'group_id' => Ids::toBinary($groupId),
                    'tender_type' => $t['tenderType'],
                    'provider' => 'MANUAL',
                    'reference' => $t['reference'],
                    'status' => 'CAPTURED',
                    'amount' => $t['amount'],
                    'tendered' => $t['tendered'],
                    'change_given' => $t['changeGiven'],
                    'currency' => 'NGN',
                    'cash_session_id' => $session ? $session['id'] : null,
                    'device_id' => Fmt::bin($deviceId),
                    'taken_by_staff_id' => Ids::toBinary($staffId),
                    'customer_name' => $in['customerName'] ?? null,
                    'receipt_id' => Ids::toBinary($receipt['id']),
                    'client_fingerprint' => $t['fingerprint'],
                    'client_created_at' => Fmt::fromIso($t['clientCreatedAt'] ?? $in['clientCreatedAt'] ?? null),
                    'captured_at' => $now,
                    'created_at' => $now,
                ];
                try {
                    DB::table('payment')->insert($row);
                    foreach ($plans[$i] as $slice) {
                        DB::table('payment_allocation')->insert([
                            'id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $row['id'],
                            'order_id' => Ids::toBinary($slice['orderId']), 'amount' => $slice['amount'],
                        ]);
                    }
                } catch (QueryException $e) {
                    throw $this->translate($e);
                }
                $paymentRows[] = [
                    'id' => $id, 'tenderType' => $t['tenderType'], 'amount' => $t['amount'], 'reference' => $t['reference'],
                    'tendered' => $t['tendered'], 'changeGiven' => $t['changeGiven'],
                    'cashSessionId' => $session ? Ids::fromBinary($session['id']) : null, 'receiptId' => $receipt['id'],
                    'takenByStaffId' => $staffId, 'allocations' => $plans[$i],
                ];
            }

            $synced = $this->effects->syncOrders($orders, $paidBefore, $allocatedNow, $groupId);
            if ($tab !== null && $this->tabFullySettled($tab, $orders, $paidBefore, $synced)) {
                $this->orders->markTabSettled($tab['id']);
            }
            $this->effects->captured($paymentRows, [
                'organizationId' => $orgId, 'siteId' => $siteId, 'facilityId' => $facilityId, 'groupId' => $groupId,
                'provider' => 'MANUAL', 'staffId' => $staffId, 'capturedAt' => Fmt::iso($now),
            ]);

            return ['body' => $this->result($groupId, $orders, $paidBefore, $synced, $receipt['id']), 'replayed' => false];
        });
    }

    /**
     * Tab settlement: allocate the full balance of every unsettled order on the tab. Tenders must cover it; any excess is
     * cash change (taken off the cash tender(s) so payment.amount is always "money applied to the bill").
     *
     * @param  array<string, array<string, mixed>>  $orders
     * @param  array<string, string>  $paid
     * @return array{0: list<array{orderId: string, amount: string}>, 1: list<array<string, mixed>>}
     */
    private function tabAllocations(array $orders, array $paid, array $tenders): array
    {
        $allocations = [];
        $due = '0.0000';
        foreach ($orders as $oid => $o) {
            if (in_array($o['status'], ['VOIDED', 'SETTLED'], true)) {
                continue;
            }
            $this->assertPayable($o);
            $balance = bcsub($o['total'], $paid[$oid], 4);
            if (bccomp($balance, '0', 4) > 0) {
                $allocations[] = ['orderId' => $oid, 'amount' => $balance];
                $due = bcadd($due, $balance, 4);
            }
        }
        if ($allocations === []) {
            throw ApiProblem::conflict('balance_changed', 'Nothing is owed on this tab any more (it may have just been settled).', ['balanceDue' => '0.0000']);
        }
        $sum = '0';
        foreach ($tenders as $t) {
            $sum = bcadd($sum, Money::normalize($t['amount']), 4);
        }
        if (bccomp($sum, $due, 4) < 0) {
            throw ApiProblem::unprocessable('amount_mismatch', "Tenders ({$sum}) do not cover the tab balance ({$due}).", []);
        }
        $excess = bcsub($sum, $due, 4);
        if (bccomp($excess, '0', 4) > 0) {
            $cashTotal = '0';
            foreach ($tenders as $t) {
                if ($t['tenderType'] === 'CASH') {
                    $cashTotal = bcadd($cashTotal, Money::normalize($t['amount']), 4);
                }
            }
            if (bccomp($excess, $cashTotal, 4) >= 0) {
                throw ApiProblem::unprocessable('amount_mismatch', 'Over-tendering is only allowed on cash and must leave part of the cash applied to the bill.');
            }
            // Take the excess off the last cash tenders; what the customer physically handed over stays in `tendered`.
            for ($i = count($tenders) - 1; $i >= 0 && bccomp($excess, '0', 4) > 0; $i--) {
                if ($tenders[$i]['tenderType'] !== 'CASH') {
                    continue;
                }
                $amt = Money::normalize($tenders[$i]['amount']);
                $tenders[$i]['tendered'] ??= $amt;
                $cut = bccomp($excess, bcsub($amt, '0.0001', 4), 4) < 0 ? $excess : bcsub($amt, '0.0001', 4);
                $tenders[$i]['amount'] = bcsub($amt, $cut, 4);
                $excess = bcsub($excess, $cut, 4);
            }
        }

        return [$allocations, $tenders];
    }

    /**
     * @param  list<array{orderId: string, amount: string}>  $requested
     * @param  array<string, array<string, mixed>>  $orders
     * @param  array<string, string>  $paid
     * @return list<array{orderId: string, amount: string}>
     */
    private function validatedAllocations(array $requested, array $orders, array $paid, string $facilityId, ?array $tab): array
    {
        $out = [];
        foreach ($requested as $a) {
            $oid = Ids::normalize($a['orderId']);
            $o = $orders[$oid];
            $amount = Money::normalize($a['amount']);
            if ($o['facilityId'] !== $facilityId && $o['paymentFacilityId'] !== $facilityId) {
                throw ApiProblem::conflict('facility_mismatch', 'The order belongs to a different facility.', ['orderId' => $oid]);
            }
            if ($tab !== null && ! in_array($oid, $tab['orderIds'], true)) {
                throw ApiProblem::unprocessable('validation_failed', 'The order is not on the given tab.', ['allocations' => ['Order '.$oid.' is not on the tab.']]);
            }
            $balance = bcsub($o['total'], $paid[$oid], 4);
            if ($o['status'] === 'SETTLED' || bccomp($balance, '0', 4) <= 0) {
                throw ApiProblem::conflict('balance_changed', 'The order has no balance due (it may have just been paid).', ['orderId' => $oid, 'balanceDue' => '0.0000']);
            }
            $this->assertPayable($o);
            if (bccomp($amount, $balance, 4) > 0) {
                throw ApiProblem::conflict('balance_changed', 'The order balance is lower than the amount you are allocating.', ['orderId' => $oid, 'balanceDue' => $balance]);
            }
            $out[] = ['orderId' => $oid, 'amount' => $amount];
        }

        return $out;
    }

    /** Order state + facility payment-timing rule (architecture/08 §1: payment timing is configurable per facility). */
    public function assertPayable(array $order): void
    {
        if (! in_array($order['status'], self::PAYABLE, true)) {
            throw ApiProblem::conflict('order_state_invalid', "An order in status {$order['status']} cannot be paid.", ['orderId' => $order['id'], 'status' => $order['status']]);
        }
        $timing = $this->rules->paymentTiming($order['facilityId']);
        if (in_array($timing, ['PAY_ON_EXIT', 'PAY_AFTER_SERVICE'], true) && $order['status'] !== 'SERVED') {
            throw ApiProblem::conflict('order_state_invalid', "This facility takes payment after service; the order is {$order['status']}.", ['orderId' => $order['id'], 'status' => $order['status'], 'paymentTiming' => $timing]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tenders
     * @return list<array<string, mixed>>
     */
    private function normalizedTenders(array $tenders): array
    {
        $out = [];
        foreach ($tenders as $t) {
            $amount = Money::normalize($t['amount']);
            $type = $t['tenderType'];
            $tendered = isset($t['tendered']) && $t['tendered'] !== null ? Money::normalize($t['tendered']) : null;
            $change = '0.0000';
            if ($type === 'CASH') {
                if ($tendered !== null) {
                    if (bccomp($tendered, $amount, 4) < 0) {
                        throw ApiProblem::unprocessable('amount_mismatch', 'Cash tendered is less than the amount applied.', ['tenders' => ['tendered must be >= amount']]);
                    }
                    $change = AllocationPlanner::change($amount, $tendered);
                }
            } else {
                if ($tendered !== null && bccomp($tendered, $amount, 4) !== 0) {
                    throw ApiProblem::unprocessable('validation_failed', 'Only cash can be tendered above the amount.', ['tenders' => ['tendered is only valid for CASH']]);
                }
                $tendered = null;
            }
            $out[] = [
                'id' => $t['id'] ?? null,
                'tenderType' => $type,
                'amount' => $amount,
                'reference' => isset($t['reference']) && $t['reference'] !== '' ? $t['reference'] : null,
                'tendered' => $tendered,
                'changeGiven' => $change,
                'clientCreatedAt' => $t['clientCreatedAt'] ?? null,
                'fingerprint' => $t['fingerprint'],
            ];
        }

        return $out;
    }

    /** @return null|array{id: string, facilityId: string} */
    private function cashSessionFor(?string $sessionId, string $facilityId, string $staffId, bool $hasCash): ?array
    {
        if ($sessionId === null && $hasCash && $this->rules->requireCashSession($facilityId)) {
            $open = DB::selectOne("SELECT id FROM cash_session WHERE staff_id = ? AND status = 'OPEN' AND facility_unit_id = ? FOR SHARE", [Ids::toBinary($staffId), Ids::toBinary($facilityId)]);
            if ($open === null) {
                throw ApiProblem::conflict('cash_session_required', 'Open a cash session before taking cash at this facility.');
            }
            $sessionId = Ids::fromBinary($open->id);
        }
        if ($sessionId === null) {
            return null;
        }
        $s = DB::selectOne('SELECT id, facility_unit_id, staff_id, status FROM cash_session WHERE id = ? FOR SHARE', [Ids::toBinary($sessionId)]);
        if ($s === null) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown cash session.', ['cashSessionId' => ['Unknown cash session.']]);
        }
        if ($s->status !== 'OPEN') {
            throw ApiProblem::conflict('cash_session_required', 'That cash session is closed; open a new one.', ['cashSessionId' => $sessionId]);
        }
        if (Ids::fromBinary($s->facility_unit_id) !== $facilityId) {
            throw ApiProblem::conflict('facility_mismatch', 'The cash session belongs to a different facility.');
        }
        if (Ids::fromBinary($s->staff_id) !== $staffId) {
            throw ApiProblem::forbidden('permission_denied', 'A cash session can only be used by the cashier who opened it.');
        }

        return ['id' => $s->id, 'facilityId' => $facilityId];
    }

    /**
     * Offline clients supply tender ids. Same id + same body => original result; same id + different body => 409.
     *
     * @param  array<string, array<string, mixed>>  $orders
     * @return null|array<string, mixed>
     */
    private function replayIfKnown(array $in, ?string $facilityId, array $orders): ?array
    {
        $ids = array_values(array_filter(array_map(fn ($t) => $t['id'] ?? null, $in['tenders'])));
        if ($ids === []) {
            return null;
        }
        $rows = DB::table('payment')->whereIn('id', array_map(Ids::toBinary(...), $ids))->get()->keyBy(fn ($r) => Ids::fromBinary($r->id));
        if ($rows->isEmpty()) {
            return null;
        }
        foreach ($in['tenders'] as $t) {
            $id = $t['id'] ?? null;
            if ($id !== null && isset($rows[$id]) && ! hash_equals((string) $rows[$id]->client_fingerprint, $t['fingerprint'])) {
                throw ApiProblem::conflict('concurrency_conflict', 'A payment with this id already exists with a different body.', ['id' => $id]);
            }
        }
        $groupId = Ids::fromBinary($rows->first()->group_id);
        $payments = $this->presenter->group($groupId);
        $orderIds = [];
        foreach ($payments as $p) {
            foreach ($p['allocations'] as $a) {
                $orderIds[$a['orderId']] = true;
            }
        }
        $paid = Ledger::paidByOrder(array_keys($orderIds));
        $cur = $this->orders->lockOrders(array_keys($orderIds));

        return [
            'payments' => $payments,
            'orders' => $this->summaries($cur, $paid),
            'receiptId' => $payments[0]['receiptId'],
            'changeDue' => array_reduce($payments, fn ($c, $p) => bcadd($c, $p['changeGiven'], 4), '0.0000'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $orders
     * @param  array<string, string>  $paidBefore
     * @param  array<string, array{paid: string, settled: bool}>  $synced
     * @return array<string, mixed>
     */
    private function result(string $groupId, array $orders, array $paidBefore, array $synced, string $receiptId): array
    {
        $paid = $paidBefore;
        $status = [];
        foreach ($synced as $oid => $s) {
            $paid[$oid] = $s['paid'];
            $status[$oid] = $s['settled'] ? 'SETTLED' : null;
        }
        $payments = $this->presenter->group($groupId);
        $touched = array_intersect_key($orders, $synced);
        foreach ($status as $oid => $st) {
            if ($st !== null) {
                $touched[$oid]['status'] = $st;
            }
        }

        return [
            'payments' => $payments,
            'orders' => $this->summaries($touched, $paid),
            'receiptId' => $receiptId,
            'changeDue' => array_reduce($payments, fn ($c, $p) => bcadd($c, $p['changeGiven'], 4), '0.0000'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $orders
     * @param  array<string, string>  $paid
     * @return list<array<string, mixed>>
     */
    public function summaries(array $orders, array $paid): array
    {
        $details = $this->orders->details(array_keys($orders));
        $out = [];
        foreach ($orders as $oid => $o) {
            $out[] = [
                'id' => $oid,
                'number' => $o['number'],
                'facilityId' => $o['facilityId'],
                'tableId' => null,
                'tableLabel' => $details[$oid]['tableLabel'] ?? null,
                'tabId' => $o['tabId'],
                'status' => $o['status'],
                'total' => $o['total'],
                'balanceDue' => bcsub($o['total'], $paid[$oid] ?? '0.0000', 4),
                'lineCount' => $details[$oid]['lineCount'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $orders
     * @param  array<string, string>  $paidBefore
     * @param  array<string, array{paid: string, settled: bool}>  $synced
     */
    private function tabFullySettled(array $tab, array $orders, array $paidBefore, array $synced): bool
    {
        foreach ($tab['orderIds'] as $oid) {
            $o = $orders[Ids::normalize($oid)];
            if ($o['status'] === 'VOIDED') {
                continue;
            }
            $paid = isset($synced[$oid]) ? $synced[$oid]['paid'] : $paidBefore[$oid];
            if (bccomp($paid, $o['total'], 4) < 0) {
                return false;
            }
        }

        return true;
    }

    private function translate(QueryException $e): ApiProblem|QueryException
    {
        $msg = $e->getMessage();
        if (($e->errorInfo[1] ?? null) === 1062) {
            if (str_contains($msg, 'uq_pay_live_ref')) {
                return ApiProblem::conflict('duplicate_reference', 'That POS / transfer reference has already been recorded on another payment.');
            }

            return ApiProblem::conflict('concurrency_conflict', 'A payment with this id already exists.');
        }

        return $e;
    }

    /** Stable hash of "what this tender asked for": used to tell a genuine replay from an id collision. */
    public static function fingerprint(array $in, array $tender, bool $tabMode): string
    {
        $alloc = array_map(fn ($a) => ['orderId' => Ids::normalize($a['orderId']), 'amount' => Money::normalize($a['amount'])], $in['allocations'] ?? []);
        usort($alloc, fn ($a, $b) => strcmp($a['orderId'], $b['orderId']));

        return hash('sha256', Audit::canonicalize([
            'tab' => $tabMode ? Ids::normalize((string) $in['tabId']) : null,
            'facilityId' => isset($in['facilityId']) ? Ids::normalize($in['facilityId']) : null,
            'allocations' => $alloc,
            'tender' => [
                'tenderType' => $tender['tenderType'],
                'amount' => Money::normalize($tender['amount']),
                'reference' => $tender['reference'] ?? null,
                'tendered' => isset($tender['tendered']) ? Money::normalize($tender['tendered']) : null,
            ],
        ]));
    }
}
