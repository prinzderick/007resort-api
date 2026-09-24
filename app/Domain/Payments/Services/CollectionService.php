<?php

namespace App\Domain\Payments\Services;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Orders\Services\Presenter as OrderPresenter;
use App\Domain\Orders\Services\Realtime;
use App\Domain\Payments\Broadcast\PaymentAlert;
use App\Domain\Payments\Broadcast\PaymentCollected;
use App\Domain\Payments\Broadcast\PaymentConfirmed;
use App\Domain\Payments\Broadcast\PaymentExpired;
use App\Domain\Payments\Broadcast\PaymentRejected;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Contracts\PaymentProviderAdapter;
use App\Domain\Payments\Provider\Terminal\TerminalCharge;
use App\Domain\Payments\Provider\VerifiedTransaction;
use App\Domain\Payments\Support\CollectionRules;
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
 * Waiter collection (docs/WAITER_COLLECTION.md). A waiter can NEVER capture money by hand: a collection is a payment INSERTED in
 * PENDING_CONFIRMATION (or AUTHORIZING for provider-verified tenders) and only a `payment.confirm` holder (or the provider) moves it to CAPTURED.
 *
 * Concurrency: every mutation takes the ORDER row locks first (like PaymentService / tab settle), then the waiter's `staff` row (cash-in-hand
 * serialisation point); confirm / reject / cancel / expire take the PAYMENT row first, then the orders, then the cash session (like refunds).
 * Over-collection is prevented under the order lock: captured + pending + new <= total. Exactly one decision row per payment
 * (`payment_collection_decision` UNIQUE) plus the payment row lock make double-confirm / confirm-vs-reject races safe.
 */
class CollectionService
{
    public function __construct(
        private readonly OrderPort $orders,
        private readonly PaymentService $payments,
        private readonly PaymentPresenter $presenter,
        private readonly PaymentEffects $effects,
        private readonly ReceiptService $receipts,
        private readonly CollectionRules $rules,
        private readonly CollectionPolicyService $policy,
        private readonly PermissionChecker $permissions,
        private readonly TerminalAdapterRegistry $terminals,
        private readonly PaymentProviderAdapter $provider,
        private readonly OrderPresenter $orderPresenter,
        private readonly Realtime $realtime,
    ) {}

    // ------------------------------------------------------------------------------------------------------------------------
    // collect
    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in  validated CollectionRequest
     * @return array{body: array<string, mixed>, replayed: bool}
     */
    public function collect(string $orderId, array $in, string $staffId): array
    {
        $fingerprint = self::fingerprint($orderId, $in);

        return DB::transaction(function () use ($orderId, $in, $staffId, $fingerprint) {
            // ---- 1. LOCK FIRST: the order. ---------------------------------------------------------------------------------
            $orders = $this->orders->lockOrders([$orderId]);
            $order = $orders[Ids::normalize($orderId)] ?? throw ApiProblem::notFound('not_found', 'Order not found.');
            $facilityId = $order['facilityId'];
            $scope = Scope::facility($facilityId);
            if (! $this->permissions->can($staffId, 'payment.collect', $scope)) {
                throw ApiProblem::permissionDenied('payment.collect');
            }

            // ---- 2. Client id replay (offline / retry): the original result, or 409 for a different body. --------------------
            if (isset($in['id']) && ($replay = $this->replay($in['id'], $fingerprint, $order)) !== null) {
                return ['body' => $replay, 'replayed' => true];
            }

            // ---- 3. Preconditions. -----------------------------------------------------------------------------------------
            $rules = $this->rules->forFacility($facilityId);
            if (! $rules['collectionEnabled']) {
                throw ApiProblem::conflict('collection_disabled', 'Collecting payment at the table is not enabled at this facility.');
            }
            $deviceId = RequestContext::deviceId();
            $this->assertDeviceCheckedOut($deviceId, $staffId, $order);
            if ($order['billPrintedAt'] === null) {
                throw ApiProblem::conflict('order_not_billed', 'Print the bill before collecting payment.');
            }
            $this->payments->assertPayable($order);

            $tender = $in['tenderType'];
            $amount = Money::normalize($in['amount']);
            $paid = Ledger::paidByOrder([$order['id']])[$order['id']];
            $pending = Ledger::pendingByOrder([$order['id']])[$order['id']];
            $balance = bcsub($order['total'], $paid, 4);
            $collectable = bcsub($balance, $pending, 4);
            if (bccomp($amount, $collectable, 4) > 0) {
                throw ApiProblem::conflict('over_collection', 'The amount is more than what can still be collected on this bill.', [
                    'balanceDue' => $balance, 'pendingCollected' => $pending, 'collectable' => bccomp($collectable, '0', 4) > 0 ? $collectable : '0.0000',
                ]);
            }

            $provider = in_array($tender, ['PAY_LINK'], true) || ($tender === 'TRANSFER' && ($in['channel'] ?? 'MANUAL') === 'PAYSTACK');
            $manual = ! $provider;
            $immediate = $manual && ! in_array($tender, $rules['requiresConfirmation'], true) && $this->permissions->can($staffId, 'payment.take', $scope);

            // ---- 4. Cash holding policy + limit (staff row = the waiter's cash-in-hand serialisation point). ------------------
            $tendered = null;
            $change = '0.0000';
            if ($tender === 'CASH') {
                if (isset($in['tendered'])) {
                    $tendered = Money::normalize($in['tendered']);
                    if (bccomp($tendered, $amount, 4) < 0) {
                        throw ApiProblem::unprocessable('amount_mismatch', 'Cash tendered is less than the amount collected.', ['tendered' => ['tendered must be >= amount']]);
                    }
                    $change = bcsub($tendered, $amount, 4);
                }
                if (! $immediate) {
                    $p = $this->policy->effective($staffId, $facilityId)['cashHolding'];
                    if (! $p['allowed']) {
                        throw ApiProblem::forbidden('cash_holding_not_allowed', 'Cash holding is not enabled for you here. Please send the customer to the cashier to pay cash.');
                    }
                    DB::selectOne('SELECT id FROM staff WHERE id = ? FOR UPDATE', [Ids::toBinary($staffId)]);
                    $inHand = $this->cashInHand($staffId);
                    if ($p['limit'] !== null && bccomp(bcadd($inHand, $amount, 4), $p['limit'], 4) > 0) {
                        throw ApiProblem::conflict('cash_limit_exceeded', 'Your cash-in-hand limit would be exceeded. Hand over cash to the cashier first.', [
                            'cashInHand' => $inHand, 'limit' => $p['limit'], 'handoverRequired' => true,
                        ]);
                    }
                }
            } elseif (isset($in['tendered'])) {
                throw ApiProblem::unprocessable('validation_failed', 'Only cash can be tendered above the amount.', ['tendered' => ['tendered is only valid for CASH']]);
            }

            $terminal = isset($in['terminalId']) ? $this->terminal($in['terminalId'], $order, $staffId, $deviceId) : null;
            if ($terminal !== null && $tender !== 'CARD_TERMINAL') {
                throw ApiProblem::unprocessable('validation_failed', 'terminalId is only valid for CARD_TERMINAL.', ['terminalId' => ['Only for CARD_TERMINAL.']]);
            }

            // ---- 5. Write. -------------------------------------------------------------------------------------------------
            ['org' => $orgId, 'site' => $siteId] = Tenantless::facility($facilityId);
            $paymentId = $in['id'] ?? Ids::uuid7();
            $now = Fmt::now();
            $expires = now('UTC')->addMinutes($rules['expiryMinutes'])->format('Y-m-d H:i:s.u');
            $reference = match ($tender) {
                'CARD_TERMINAL' => ($in['approvalCode'] ?? null) ?: ($in['slipReference'] ?? null),
                'TRANSFER' => $manual ? ($in['bankReference'] ?? null) : null,
                default => null,
            };
            $providerPayload = null;
            $row = [
                'id' => Ids::toBinary($paymentId), 'organization_id' => Ids::toBinary($orgId), 'site_id' => Ids::toBinary($siteId),
                'facility_unit_id' => Ids::toBinary($facilityId), 'group_id' => Ids::toBinary($paymentId),
                'tender_type' => match ($tender) {'CARD_TERMINAL' => 'POS_TERMINAL', 'PAY_LINK' => 'CARD', default => $tender},
                'provider' => 'MANUAL', 'reference' => $reference !== '' ? $reference : null, 'status' => 'PENDING_CONFIRMATION',
                'amount' => $amount, 'tendered' => $tendered, 'change_given' => $change, 'currency' => 'NGN',
                'device_id' => Fmt::bin($deviceId), 'taken_by_staff_id' => Ids::toBinary($staffId),
                'client_fingerprint' => $fingerprint, 'client_created_at' => Fmt::fromIso($in['clientCreatedAt'] ?? null), 'created_at' => $now,
            ];
            $channel = 'MANUAL';
            $autoConfirm = 0;
            $terminalCharge = null;

            if ($provider) {
                $email = $in['customerEmail'] ?? (string) config('payments.collection.default_customer_email');
                $ref = 'R007-'.strtoupper(str_replace('-', '', $paymentId));
                $meta = ['orderNumbers' => [$order['number']], 'collection' => true, 'paymentId' => $paymentId];
                if ($tender === 'PAY_LINK') {
                    $init = $this->provider->initialize($ref, $amount, 'NGN', $email, null, $meta);
                    $providerPayload = ['authorizationUrl' => $init['authorizationUrl'], 'accessCode' => $init['accessCode'], 'reference' => $ref];
                } else {
                    $acct = $this->provider->createTransferAccount($ref, $amount, 'NGN', $email, $meta);
                    $providerPayload = ['transferAccount' => $acct, 'reference' => $ref];
                }
                $channel = 'PAYSTACK';
                $autoConfirm = 1;
                $row = array_merge($row, [
                    'provider' => 'PAYSTACK', 'provider_reference' => $ref, 'status' => 'AUTHORIZING', 'customer_email' => $email,
                    'intent' => json_encode(['allocations' => [['orderId' => $order['id'], 'amount' => $amount]]], JSON_THROW_ON_ERROR),
                ]);
            } elseif ($terminal !== null) {
                $adapter = $this->terminals->for($terminal->provider);
                $ref = 'T007-'.strtoupper(str_replace('-', '', $paymentId));
                $terminalCharge = $adapter->initiateCharge($terminal, $ref, $amount, ['orderNumber' => $order['number'], 'facilityId' => $facilityId, 'staffId' => $staffId]);
                if ($terminalCharge->status === TerminalCharge::FAILED) {
                    throw ApiProblem::conflict('terminal_charge_failed', $terminalCharge->message ?: 'The terminal refused the charge.');
                }
                $channel = 'TERMINAL';
                if ($adapter->code() !== 'MANUAL_BANK') {
                    $row['provider_reference'] = $ref;
                    $autoConfirm = 1; // an integrated terminal: the adapter (not a person) confirms
                }
            }

            try {
                DB::table('payment')->insert($row);
                DB::table('payment_allocation')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $row['id'], 'order_id' => Ids::toBinary($order['id']), 'amount' => $amount]);
                DB::table('payment_collection')->insert([
                    'payment_id' => $row['id'], 'organization_id' => $row['organization_id'], 'site_id' => $row['site_id'], 'facility_unit_id' => $row['facility_unit_id'],
                    'order_id' => Ids::toBinary($order['id']), 'tender' => $tender, 'channel' => $channel, 'collected_by_staff_id' => $row['taken_by_staff_id'],
                    'device_id' => $row['device_id'], 'terminal_id' => $terminal?->id, 'approval_code' => $in['approvalCode'] ?? null,
                    'slip_reference' => $in['slipReference'] ?? null, 'last4' => $in['last4'] ?? null, 'bank_reference' => $in['bankReference'] ?? null,
                    'note' => $in['note'] ?? null, 'client_created_at' => $row['client_created_at'],
                    'provider_payload' => $providerPayload === null ? null : json_encode($providerPayload, JSON_THROW_ON_ERROR),
                    'auto_confirm' => $autoConfirm, 'expires_at' => $expires, 'created_at' => $now,
                ]);
                if ($tender === 'CASH' && ! $immediate) {
                    DB::table('cash_in_hand_entry')->insert([
                        'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $row['organization_id'], 'site_id' => $row['site_id'], 'facility_unit_id' => $row['facility_unit_id'],
                        'staff_id' => $row['taken_by_staff_id'], 'kind' => 'COLLECTED', 'amount' => $amount, 'payment_id' => $row['id'], 'created_at' => $now,
                    ]);
                }
            } catch (QueryException $e) {
                throw $this->translate($e);
            }

            Audit::record('payment.collect', 'Payment', $paymentId, new: [
                'orderId' => $order['id'], 'orderNumber' => $order['number'], 'tender' => $tender, 'amount' => $amount, 'reference' => $row['reference'],
                'terminalId' => $terminal ? Ids::fromBinary($terminal->id) : null, 'status' => $row['status'], 'immediate' => $immediate,
            ], organizationId: $orgId, siteId: $siteId, actorStaffId: $staffId, facilityUnitId: $facilityId);
            Outbox::record('PaymentCollected', 'Payment', $paymentId, [
                'paymentId' => $paymentId, 'orderId' => $order['id'], 'orderNumber' => $order['number'], 'facilityId' => $facilityId, 'tender' => $tender, 'amount' => $amount,
                'status' => $row['status'], 'collectedByStaffId' => $staffId, 'reference' => $row['reference'], 'expiresAt' => Fmt::iso($expires), 'currency' => 'NGN',
            ], organizationId: $orgId, siteId: $siteId, facilityId: $facilityId);

            $pRow = DB::table('payment')->where('id', $row['id'])->first();
            $this->emit(PaymentCollected::class, $pRow, ['orderId' => $order['id'], 'orderNumber' => $order['number'], 'tableLabel' => $this->tableLabel($order['tableId']), 'collectedByStaffId' => $staffId, 'amount' => $amount, 'tender' => $tender, 'payment' => $this->presenter->one($pRow)]);

            if ($immediate) {
                $this->capturePending(DB::selectOne('SELECT * FROM payment WHERE id = ? FOR UPDATE', [$row['id']]), 'MANUAL', $staffId, null, 'captured by collector (rule)');
            } elseif ($terminalCharge !== null && $terminalCharge->status === TerminalCharge::CONFIRMED) {
                $this->capturePending(DB::selectOne('SELECT * FROM payment WHERE id = ? FOR UPDATE', [$row['id']]), 'PROVIDER', null, $terminalCharge->providerReference, null);
            }

            return ['body' => $this->result($paymentId, $order['id']), 'replayed' => false];
        });
    }

    // ------------------------------------------------------------------------------------------------------------------------
    // confirm / reject / cancel
    // ------------------------------------------------------------------------------------------------------------------------

    /** @return array{body: array<string, mixed>, replayed: bool} */
    public function confirm(string $paymentId, array $in, string $staffId): array
    {
        return DB::transaction(function () use ($paymentId, $in, $staffId) {
            $p = $this->lockCollection($paymentId); // FIRST statement: the payment row
            $facility = Ids::fromBinary($p->facility_unit_id);
            $this->requireConfirm($staffId, $facility);
            $d = DB::table('payment_collection_decision')->where('payment_id', $p->id)->first();

            if ($p->status !== 'PENDING_CONFIRMATION') {
                if ($d !== null && $d->decision === 'CONFIRMED' && in_array($p->status, ['CAPTURED', 'PARTIALLY_REFUNDED', 'REFUNDED', 'REVERSED'], true)) {
                    return ['body' => $this->result($paymentId, $this->orderIdOf($p)), 'replayed' => true]; // idempotent double confirm
                }
                if ($p->status === 'AUTHORIZING') {
                    throw ApiProblem::conflict('auto_confirm_only', 'This payment is confirmed by the payment provider; it cannot be confirmed by hand.');
                }
                throw ApiProblem::conflict('payment_state_invalid', "A payment that is {$p->status} cannot be confirmed.", ['status' => $p->status]);
            }
            $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
            if ((bool) $c->auto_confirm) {
                throw ApiProblem::conflict('auto_confirm_only', 'This payment is confirmed by the payment provider; it cannot be confirmed by hand.');
            }
            if (Ids::fromBinary($c->collected_by_staff_id) === $staffId) {
                throw ApiProblem::forbidden('self_confirmation_forbidden', 'You cannot confirm money that you collected yourself; ask a cashier or supervisor.');
            }
            $this->capturePending($p, 'MANUAL', $staffId, $in['matchedReference'] ?? null, $in['note'] ?? null);

            return ['body' => $this->result($paymentId, $this->orderIdOf($p)), 'replayed' => false];
        });
    }

    /** @return array{body: array<string, mixed>, replayed: bool} */
    public function reject(string $paymentId, string $reason, string $staffId): array
    {
        return DB::transaction(function () use ($paymentId, $reason, $staffId) {
            $p = $this->lockCollection($paymentId);
            $facility = Ids::fromBinary($p->facility_unit_id);
            $this->requireConfirm($staffId, $facility);
            if ($p->status === 'REJECTED') {
                return ['body' => $this->presenter->one($p), 'replayed' => true];
            }
            if ($p->status === 'AUTHORIZING') {
                throw ApiProblem::conflict('auto_confirm_only', 'This payment is settled by the payment provider; use cancel to release the balance.');
            }
            if ($p->status !== 'PENDING_CONFIRMATION') {
                throw ApiProblem::conflict('payment_state_invalid', "A payment that is {$p->status} cannot be rejected (use refund / reversal after confirmation).", ['status' => $p->status]);
            }
            $this->finish($p, 'REJECTED', 'REJECTED', 'MANUAL', $staffId, $reason);
            $fresh = DB::table('payment')->where('id', $p->id)->first();
            $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
            Audit::securityEvent('payment.collection_rejected', 'WARNING', $staffId, null, [
                'paymentId' => $paymentId, 'facilityId' => $facility, 'amount' => Money::normalize((string) $p->amount), 'tender' => $c->tender,
                'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id), 'reason' => $reason,
            ]);
            $payload = ['payment' => $this->presenter->one($fresh), 'orderId' => Ids::fromBinary($c->order_id), 'reason' => $reason];
            $this->emit(PaymentRejected::class, $fresh, $payload);
            $this->emit(PaymentAlert::class, $fresh, ['kind' => 'REJECTED', 'message' => 'A collection was rejected: '.$reason] + $payload, device: false);

            return ['body' => $this->presenter->one($fresh), 'replayed' => false];
        });
    }

    /** Release an unpaid pay-link / provider-transfer (or, for a `payment.confirm` holder, any pending collection). */
    public function cancel(string $paymentId, ?string $reason, string $staffId): array
    {
        // Ask the provider FIRST (outside our transaction): if the customer already paid, capture instead of cancelling.
        $pre = DB::table('payment')->where('id', Ids::toBinary($paymentId))->first();
        if ($pre !== null && $pre->status === 'AUTHORIZING' && $pre->provider === 'PAYSTACK' && $pre->provider_reference !== null) {
            $tx = $this->provider->verify((string) $pre->provider_reference);
            if ($tx->status === VerifiedTransaction::SUCCESS) {
                app(PaystackService::class)->confirm((string) $pre->provider_reference, null);
                throw ApiProblem::conflict('already_paid', 'The customer has already paid this; it has been confirmed.');
            }
        }

        return DB::transaction(function () use ($paymentId, $reason, $staffId) {
            $p = $this->lockCollection($paymentId);
            $facility = Ids::fromBinary($p->facility_unit_id);
            $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
            $isCollector = Ids::fromBinary($c->collected_by_staff_id) === $staffId;
            $canConfirm = $this->permissions->can($staffId, 'payment.confirm', Scope::facility($facility));
            if ($p->status === 'CANCELLED') {
                return $this->presenter->one($p);
            }
            if (! $canConfirm && ! ($isCollector && $p->status === 'AUTHORIZING' && $this->permissions->can($staffId, 'payment.collect', Scope::facility($facility)))) {
                throw ApiProblem::permissionDenied('payment.confirm');
            }
            if (! in_array($p->status, ['AUTHORIZING', 'PENDING_CONFIRMATION'], true)) {
                throw ApiProblem::conflict('payment_state_invalid', "A payment that is {$p->status} cannot be cancelled.", ['status' => $p->status]);
            }
            $this->finish($p, 'CANCELLED', 'CANCELLED', 'MANUAL', $staffId, $reason ?: 'cancelled');
            $fresh = DB::table('payment')->where('id', $p->id)->first();
            $this->emit(PaymentRejected::class, $fresh, ['payment' => $this->presenter->one($fresh), 'orderId' => Ids::fromBinary($c->order_id), 'reason' => $reason ?: 'cancelled', 'decision' => 'CANCELLED']);

            return $this->presenter->one($fresh);
        });
    }

    // ------------------------------------------------------------------------------------------------------------------------
    // provider hooks + expiry
    // ------------------------------------------------------------------------------------------------------------------------

    /**
     * A provider-verified capture of a waiter collection just happened (Paystack webhook/verify, inside the capturing transaction):
     * record the decision, audit, outbox, tell the collecting waiter's device.
     */
    public function afterProviderCapture(string $paymentId): void
    {
        $bin = Ids::toBinary($paymentId);
        $c = DB::table('payment_collection')->where('payment_id', $bin)->first();
        if ($c === null || DB::table('payment_collection_decision')->where('payment_id', $bin)->exists()) {
            return;
        }
        $p = DB::table('payment')->where('id', $bin)->first();
        DB::table('payment_collection_decision')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $bin, 'decision' => 'CONFIRMED', 'mode' => 'PROVIDER', 'matched_reference' => $p->provider_reference, 'decided_at' => Fmt::now(),
        ]);
        $facility = Ids::fromBinary($p->facility_unit_id);
        Audit::record('payment.collection.auto_confirm', 'Payment', $paymentId, new: ['mode' => 'PROVIDER', 'reference' => $p->provider_reference, 'amount' => Money::normalize((string) $p->amount)],
            organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), actorStaffId: null, facilityUnitId: $facility);
        Outbox::record('PaymentConfirmed', 'Payment', $paymentId, [
            'paymentId' => $paymentId, 'orderId' => Ids::fromBinary($c->order_id), 'facilityId' => $facility, 'tender' => $c->tender, 'amount' => Money::normalize((string) $p->amount),
            'mode' => 'PROVIDER', 'receiptId' => Fmt::uuid($p->receipt_id), 'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id), 'currency' => 'NGN',
        ], organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), facilityId: $facility);
        $this->emit(PaymentConfirmed::class, $p, ['payment' => $this->presenter->one($p), 'orderId' => Ids::fromBinary($c->order_id), 'mode' => 'PROVIDER', 'receiptId' => Fmt::uuid($p->receipt_id)]);
    }

    /** A signature-verified terminal callback / status query says the charge is confirmed. */
    public function confirmFromTerminal(string $providerReference): bool
    {
        $pre = DB::table('payment')->where('provider_reference', $providerReference)->first();
        if ($pre === null) {
            return false;
        }

        return DB::transaction(function () use ($pre) {
            $p = $this->lockCollection(Ids::fromBinary($pre->id));
            if ($p->status !== 'PENDING_CONFIRMATION') {
                return false;
            }
            $this->capturePending($p, 'PROVIDER', null, $p->provider_reference, null);

            return true;
        });
    }

    /**
     * Expire collections older than their window. Cash stays in the waiter's cash-in-hand (they remain accountable).
     *
     * @return int how many collections were expired / released
     */
    public function expireDue(int $limit = 200): int
    {
        $due = DB::select(
            "SELECT c.payment_id FROM payment_collection c JOIN payment p ON p.id = c.payment_id
             WHERE p.status IN ('PENDING_CONFIRMATION','AUTHORIZING') AND c.expires_at <= ? ORDER BY c.expires_at LIMIT {$limit}", [Fmt::now()]);
        $n = 0;
        foreach ($due as $r) {
            $id = Ids::fromBinary($r->payment_id);
            $pre = DB::table('payment')->where('id', $r->payment_id)->first();
            if ($pre->status === 'AUTHORIZING' && $pre->provider === 'PAYSTACK' && $pre->provider_reference !== null) {
                try {
                    if ($this->provider->verify((string) $pre->provider_reference)->status === VerifiedTransaction::SUCCESS) {
                        app(PaystackService::class)->confirm((string) $pre->provider_reference, null); // paid after all: capture it
                        continue;
                    }
                } catch (ApiProblem) {
                    continue; // provider unreachable: try again next minute rather than releasing a balance that may be paid
                }
            }
            $n += DB::transaction(function () use ($id) {
                $p = $this->lockCollection($id);
                $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
                if (! in_array($p->status, ['PENDING_CONFIRMATION', 'AUTHORIZING'], true) || $c->expires_at > Fmt::now()) {
                    return 0;
                }
                $terminal = $p->status === 'PENDING_CONFIRMATION' ? 'EXPIRED' : 'CANCELLED';
                $this->finish($p, $terminal, 'EXPIRED', 'SYSTEM', null, 'no confirmation within the window');
                $fresh = DB::table('payment')->where('id', $p->id)->first();
                $facility = Ids::fromBinary($p->facility_unit_id);
                Audit::securityEvent('payment.collection_expired', 'WARNING', null, null, [
                    'paymentId' => $id, 'facilityId' => $facility, 'amount' => Money::normalize((string) $p->amount), 'tender' => $c->tender,
                    'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id), 'cashStaysInHand' => $c->tender === 'CASH',
                ]);
                $payload = ['payment' => $this->presenter->one($fresh), 'orderId' => Ids::fromBinary($c->order_id), 'reason' => 'expired'];
                $this->emit(PaymentExpired::class, $fresh, $payload);
                $this->emit(PaymentAlert::class, $fresh, ['kind' => 'EXPIRED', 'message' => 'A collection expired without confirmation.'] + $payload, device: false);

                return 1;
            });
        }

        return $n;
    }

    // ------------------------------------------------------------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------------------------------------------------------------

    /** Lock the payment row (FIRST statement of the transaction) and require it to be a waiter collection. */
    private function lockCollection(string $paymentId): object
    {
        if (! Ids::isUuid($paymentId)) {
            throw ApiProblem::notFound('not_found', 'Payment not found.');
        }
        $p = DB::selectOne('SELECT * FROM payment WHERE id = ? FOR UPDATE', [Ids::toBinary($paymentId)]);
        if ($p === null || ! DB::table('payment_collection')->where('payment_id', $p->id)->exists()) {
            throw ApiProblem::notFound('not_found', 'Collection not found.');
        }

        return $p;
    }

    private function requireConfirm(string $staffId, string $facilityId): void
    {
        if (! $this->permissions->can($staffId, 'payment.confirm', Scope::facility($facilityId))) {
            throw ApiProblem::permissionDenied('payment.confirm');
        }
    }

    /** PENDING -> CAPTURED. Caller holds the payment row lock and the transaction. */
    private function capturePending(object $p, string $mode, ?string $staffId, ?string $matchedRef, ?string $note): void
    {
        $paymentId = Ids::fromBinary($p->id);
        $facilityId = Ids::fromBinary($p->facility_unit_id);
        $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
        $alloc = DB::table('payment_allocation')->where('payment_id', $p->id)->orderBy('id')->get(['order_id', 'amount']);
        $orders = $this->orders->lockOrders($alloc->map(fn ($a) => Ids::fromBinary($a->order_id))->all()); // payment -> orders
        $paidBefore = Ledger::paidByOrder(array_keys($orders));
        $slices = [];
        $allocatedNow = [];
        $balanceDue = '0.0000';
        foreach ($alloc as $a) {
            $oid = Ids::fromBinary($a->order_id);
            $amt = Money::normalize((string) $a->amount);
            $o = $orders[$oid] ?? throw ApiProblem::notFound('not_found', 'Order not found.');
            if (in_array($o['status'], ['VOIDED', 'PENDING_APPROVAL', 'SETTLED'], true)) {
                throw ApiProblem::conflict('order_state_invalid', "The order is {$o['status']}; this collection cannot be confirmed. Reject it.", ['orderId' => $oid, 'status' => $o['status']]);
            }
            if (bccomp(bcadd($paidBefore[$oid], $amt, 4), $o['total'], 4) > 0) {
                throw ApiProblem::conflict('balance_changed', 'The order was paid another way in the meantime; reject this collection.', ['orderId' => $oid, 'balanceDue' => bcsub($o['total'], $paidBefore[$oid], 4)]);
            }
            $slices[] = ['orderId' => $oid, 'amount' => $amt];
            $allocatedNow[$oid] = $amt;
            $balanceDue = bcadd($balanceDue, bcsub($o['total'], bcadd($paidBefore[$oid], $amt, 4), 4), 4);
        }

        $orgId = Ids::fromBinary($p->organization_id);
        $siteId = Ids::fromBinary($p->site_id);
        $session = $this->sessionFor($staffId ?? Ids::fromBinary($c->collected_by_staff_id), $facilityId, $orders, $p->tender_type === 'CASH' && $c->tender === 'CASH');
        $receipt = $this->receipts->issue([
            'organizationId' => $orgId, 'siteId' => $siteId, 'facilityId' => $facilityId, 'groupId' => Ids::fromBinary($p->group_id),
            'staffId' => $staffId ?? Ids::fromBinary($c->collected_by_staff_id), 'deviceId' => RequestContext::deviceId() ?? Fmt::uuid($p->device_id),
            'orders' => array_values(array_intersect_key($orders, $allocatedNow)), 'details' => $this->orders->details(array_keys($allocatedNow)),
            'tenders' => [[
                'tenderType' => $p->tender_type, 'amount' => Money::normalize((string) $p->amount), 'reference' => $p->reference,
                'tendered' => $p->tendered === null ? null : Money::normalize((string) $p->tendered), 'changeGiven' => Money::normalize((string) $p->change_given),
            ]],
            'amountPaid' => Money::normalize((string) $p->amount), 'balanceDue' => $balanceDue,
        ]);
        $now = Fmt::now();
        DB::table('payment')->where('id', $p->id)->update([
            'status' => 'CAPTURED', 'captured_at' => $now, 'receipt_id' => Ids::toBinary($receipt['id']), 'cash_session_id' => $session,
            'row_version' => $p->row_version + 1,
        ]);
        DB::table('payment_collection_decision')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $p->id, 'decision' => 'CONFIRMED', 'mode' => $mode, 'decided_by_staff_id' => Fmt::bin($staffId),
            'reason' => $note, 'matched_reference' => $matchedRef, 'decided_at' => $now,
        ]);
        $synced = $this->effects->syncOrders($orders, $paidBefore, $allocatedNow, Ids::fromBinary($p->group_id));
        $this->effects->captured([[
            'id' => $paymentId, 'tenderType' => $p->tender_type, 'amount' => Money::normalize((string) $p->amount), 'reference' => $p->reference,
            'tendered' => $p->tendered === null ? null : Money::normalize((string) $p->tendered), 'changeGiven' => Money::normalize((string) $p->change_given),
            'cashSessionId' => Fmt::uuid($session), 'receiptId' => $receipt['id'], 'takenByStaffId' => Ids::fromBinary($p->taken_by_staff_id), 'allocations' => $slices,
        ]], [
            'organizationId' => $orgId, 'siteId' => $siteId, 'facilityId' => $facilityId, 'groupId' => Ids::fromBinary($p->group_id), 'provider' => 'MANUAL',
            'staffId' => $staffId, 'capturedAt' => Fmt::iso($now),
        ]);
        Audit::record('payment.confirm', 'Payment', $paymentId, old: ['status' => 'PENDING_CONFIRMATION'], new: [
            'status' => 'CAPTURED', 'mode' => $mode, 'receiptId' => $receipt['id'], 'matchedReference' => $matchedRef, 'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id),
        ], organizationId: $orgId, siteId: $siteId, actorStaffId: $staffId, facilityUnitId: $facilityId);
        Outbox::record('PaymentConfirmed', 'Payment', $paymentId, [
            'paymentId' => $paymentId, 'orderId' => Ids::fromBinary($c->order_id), 'facilityId' => $facilityId, 'tender' => $c->tender, 'amount' => Money::normalize((string) $p->amount),
            'mode' => $mode, 'confirmedByStaffId' => $staffId, 'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id), 'receiptId' => $receipt['id'],
            'matchedReference' => $matchedRef, 'orderStatuses' => array_map(fn ($s) => $s['status'], $synced), 'currency' => 'NGN',
        ], organizationId: $orgId, siteId: $siteId, facilityId: $facilityId);
        $fresh = DB::table('payment')->where('id', $p->id)->first();
        $this->emit(PaymentConfirmed::class, $fresh, ['payment' => $this->presenter->one($fresh), 'orderId' => Ids::fromBinary($c->order_id), 'mode' => $mode, 'receiptId' => $receipt['id']]);
    }

    /** Terminal, non-captured outcome (REJECTED / EXPIRED / CANCELLED): status transition + the single decision row + audit + outbox. */
    private function finish(object $p, string $status, string $decision, string $mode, ?string $staffId, ?string $reason): void
    {
        $paymentId = Ids::fromBinary($p->id);
        $facilityId = Ids::fromBinary($p->facility_unit_id);
        DB::table('payment')->where('id', $p->id)->update(['status' => $status, 'failure_reason' => $reason === null ? null : mb_substr($reason, 0, 255), 'row_version' => $p->row_version + 1]);
        DB::table('payment_collection_decision')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $p->id, 'decision' => $decision, 'mode' => $mode, 'decided_by_staff_id' => Fmt::bin($staffId),
            'reason' => $reason === null ? null : mb_substr($reason, 0, 255), 'decided_at' => Fmt::now(),
        ]);
        $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
        $orgId = Ids::fromBinary($p->organization_id);
        $siteId = Ids::fromBinary($p->site_id);
        Audit::record('payment.collection.'.strtolower($decision), 'Payment', $paymentId, old: ['status' => $p->status], new: ['status' => $status, 'reason' => $reason, 'mode' => $mode, 'tender' => $c->tender, 'amount' => Money::normalize((string) $p->amount)],
            organizationId: $orgId, siteId: $siteId, actorStaffId: $staffId, facilityUnitId: $facilityId);
        Outbox::record('PaymentRejected', 'Payment', $paymentId, [
            'paymentId' => $paymentId, 'orderId' => Ids::fromBinary($c->order_id), 'facilityId' => $facilityId, 'decision' => $decision, 'status' => $status, 'tender' => $c->tender,
            'amount' => Money::normalize((string) $p->amount), 'reason' => $reason, 'decidedByStaffId' => $staffId, 'collectedByStaffId' => Ids::fromBinary($c->collected_by_staff_id), 'currency' => 'NGN',
        ], organizationId: $orgId, siteId: $siteId, facilityId: $facilityId);
    }

    /**
     * The confirming cashier's OPEN session at the payment/order facility (cash is booked into it). Cash needs one when the facility says so.
     *
     * @param  array<string, array<string, mixed>>  $orders
     */
    private function sessionFor(string $staffId, string $facilityId, array $orders, bool $cash): ?string
    {
        $facilities = [$facilityId];
        foreach ($orders as $o) {
            if ($o['paymentFacilityId'] !== null) {
                $facilities[] = $o['paymentFacilityId'];
            }
        }
        $in = implode(',', array_fill(0, count(array_unique($facilities)), '?'));
        $s = DB::selectOne("SELECT id FROM cash_session WHERE staff_id = ? AND status = 'OPEN' AND facility_unit_id IN ($in) ORDER BY opened_at DESC LIMIT 1 FOR SHARE",
            array_merge([Ids::toBinary($staffId)], array_map(Ids::toBinary(...), array_values(array_unique($facilities)))));
        if ($s === null && $cash && app(\App\Domain\Payments\Support\FacilityRules::class)->requireCashSession($facilityId)) {
            throw ApiProblem::conflict('cash_session_required', 'Open a cash session before confirming cash.');
        }

        return $s?->id;
    }

    /** @param array<string, mixed> $order */
    private function assertDeviceCheckedOut(?string $deviceId, string $staffId, array $order): void
    {
        $fail = fn (string $why) => ApiProblem::forbidden('device_not_checked_out', $why);
        if ($deviceId === null) {
            throw $fail('Collect payments from a registered tablet that is checked out to you.');
        }
        $facilities = array_values(array_filter([$order['facilityId'], $order['paymentFacilityId']]));
        $ok = DB::table('tablet_checkout')->where('device_id', Ids::toBinary($deviceId))->where('staff_id', Ids::toBinary($staffId))->whereNull('checked_in_at')
            ->whereIn('facility_unit_id', array_map(Ids::toBinary(...), $facilities))->exists();
        if (! $ok) {
            throw $fail('This tablet is not checked out to you at this facility.');
        }
    }

    /** @param array<string, mixed> $order */
    private function terminal(string $terminalId, array $order, string $staffId, ?string $deviceId): object
    {
        $t = DB::table('payment_terminal')->where('id', Ids::toBinary($terminalId))->first();
        $facilities = array_filter([$order['facilityId'], $order['paymentFacilityId']]);
        $bad = fn (string $why) => ApiProblem::unprocessable('terminal_unavailable', $why, ['terminalId' => [$why]]);
        if ($t === null || $t->status !== 'ACTIVE') {
            throw $bad('That terminal does not exist or is not active.');
        }
        if (! in_array(Ids::fromBinary($t->facility_unit_id), $facilities, true)) {
            throw $bad('That terminal belongs to a different facility.');
        }
        if ($t->assigned_staff_id !== null && Ids::fromBinary($t->assigned_staff_id) !== $staffId) {
            throw $bad('That terminal is assigned to another staff member.');
        }
        if ($t->assigned_device_id !== null && ($deviceId === null || Ids::fromBinary($t->assigned_device_id) !== $deviceId)) {
            throw $bad('That terminal is assigned to another device.');
        }
        if ($t->provider === 'PAYSTACK_TERMINAL' && ! config('payments.terminals.paystack_enabled')) {
            throw new ApiProblem(501, 'terminal_provider_unavailable', 'The Paystack terminal integration is not enabled on this node.', 'Not implemented');
        }

        return $t;
    }

    public function cashInHand(string $staffId): string
    {
        return Money::normalize((string) (DB::table('cash_in_hand_entry')->where('staff_id', Ids::toBinary($staffId))->sum('amount') ?? '0'));
    }

    /** @return null|array<string, mixed> the original result when this client id was already used with the same body */
    private function replay(string $clientId, string $fingerprint, array $order): ?array
    {
        $existing = DB::table('payment')->where('id', Ids::toBinary($clientId))->first();
        if ($existing === null) {
            return null;
        }
        $c = DB::table('payment_collection')->where('payment_id', $existing->id)->first();
        if ($c === null || Ids::fromBinary($c->order_id) !== $order['id'] || ! hash_equals((string) $existing->client_fingerprint, $fingerprint)) {
            throw ApiProblem::conflict('concurrency_conflict', 'A payment with this id already exists with a different body.', ['id' => $clientId]);
        }

        return $this->result($clientId, $order['id']);
    }

    /** @return array<string, mixed> */
    private function result(string $paymentId, string $orderId): array
    {
        $p = DB::table('payment')->where('id', Ids::toBinary($paymentId))->first();
        $c = DB::table('payment_collection')->where('payment_id', $p->id)->first();
        $payload = $c && $c->provider_payload ? json_decode((string) $c->provider_payload, true) : null;
        $order = DB::table('order')->where('id', Ids::toBinary($orderId))->first();

        return [
            'payment' => $this->presenter->one($p),
            'order' => $this->orderPresenter->summary($order),
            'payLink' => isset($payload['authorizationUrl']) ? ['authorizationUrl' => $payload['authorizationUrl'], 'accessCode' => $payload['accessCode'] ?? null, 'reference' => $payload['reference']] : null,
            'transferAccount' => $payload['transferAccount'] ?? null,
            'receiptId' => Fmt::uuid($p->receipt_id),
        ];
    }

    private function orderIdOf(object $p): string
    {
        return Ids::fromBinary(DB::table('payment_collection')->where('payment_id', $p->id)->value('order_id'));
    }

    private function tableLabel(?string $tableId): ?string
    {
        return $tableId === null ? null : DB::table('dining_table')->where('id', Ids::toBinary($tableId))->value('label');
    }

    /** Realtime hint (after commit): the facility orders channel + (optionally) the collecting waiter's device channel. @param class-string $event */
    private function emit(string $event, object $paymentRow, array $data, bool $device = true): void
    {
        $channels = [$this->realtime->facilityOrders(Ids::fromBinary($paymentRow->facility_unit_id))];
        $collectingDevice = DB::table('payment_collection')->where('payment_id', $paymentRow->id)->value('device_id') ?? $paymentRow->device_id;
        if ($device && $collectingDevice !== null) {
            $channels[] = 'device.'.Ids::fromBinary($collectingDevice);
        }
        event(new $event($channels, $data));
    }

    private function translate(QueryException $e): ApiProblem|QueryException
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            if (str_contains($e->getMessage(), 'uq_pay_live_ref')) {
                return ApiProblem::conflict('duplicate_reference', 'That slip / approval / bank reference has already been recorded on another payment.');
            }

            return ApiProblem::conflict('concurrency_conflict', 'A payment with this id already exists.');
        }

        return $e;
    }

    /** Stable hash of "what this collection asked for" (replay vs id collision). */
    public static function fingerprint(string $orderId, array $in): string
    {
        return hash('sha256', Audit::canonicalize([
            'orderId' => Ids::normalize($orderId), 'tenderType' => $in['tenderType'], 'amount' => Money::normalize($in['amount']),
            'tendered' => isset($in['tendered']) ? Money::normalize($in['tendered']) : null, 'terminalId' => isset($in['terminalId']) ? Ids::normalize($in['terminalId']) : null,
            'approvalCode' => $in['approvalCode'] ?? null, 'slipReference' => $in['slipReference'] ?? null, 'last4' => $in['last4'] ?? null,
            'bankReference' => $in['bankReference'] ?? null, 'channel' => $in['channel'] ?? null,
        ]));
    }
}
