<?php

namespace App\Domain\Payments\Services;

use App\Domain\Customer\Support\Actor;
use App\Domain\Customer\Support\Owns;
use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Contracts\OrderPort;
use App\Domain\Payments\Contracts\PayableSubjectResolver;
use App\Domain\Payments\Contracts\PaymentProviderAdapter;
use App\Domain\Payments\Provider\VerifiedTransaction;
use App\Domain\Payments\Provider\WebhookEvent;
use App\Domain\Payments\Support\CollectionCaptureConflict;
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
 * Online payments through Paystack (ADR-0009).
 *
 * Trust model: a payment becomes CAPTURED only after the provider CONFIRMS it server-to-server (adapter->verify), never
 * because a webhook body, a redirect or a client said so. The webhook signature only decides whether we bother to ask.
 *
 * Duplicate protection (architecture/07 §4): provider_event UNIQUE(provider, provider_event_id) + the payment row lock and
 * status check under it. N simultaneous identical webhooks => one provider_event row, one capture, N-1 `duplicate: true` acks.
 * The provider HTTP call happens OUTSIDE our transaction (no row locks held while we wait for the network).
 */
class PaystackService
{
    public function __construct(
        private readonly PaymentProviderAdapter $adapter,
        private readonly OrderPort $orders,
        private readonly PayableSubjectResolver $subjects,
        private readonly PaymentEffects $effects,
        private readonly ReceiptService $receipts,
        private readonly PaymentPresenter $presenter,
        private readonly PaymentService $payments,
        private readonly PermissionChecker $permissions,
    ) {}

    /**
     * @param  array{orderIds?: list<string>, bookingId?: ?string, membershipId?: ?string, amount: string, email: string, callbackUrl?: ?string}  $in
     * @return array{paymentId: string, reference: string, authorizationUrl: string, accessCode: ?string}
     */
    public function initialize(array $in, ?string $staffId): array
    {
        $online = $staffId === null; // an online CUSTOMER (never staff): ownership replaces the payment.take permission
        if ($online) {
            $cid = Actor::requireCustomer();
            $in['email'] = (string) DB::table('customer_account')->where('customer_id', Ids::toBinary($cid))->value('login_email');
            if ($in['email'] === '') { // social sign-in without a verified email: Paystack needs one
                throw ApiProblem::conflict('profile_incomplete', 'Add and verify your email address before paying.', ['meta' => ['missing' => ['email']]]);
            }
            self::assertCallbackAllowed($in['callbackUrl'] ?? null);
        }
        $amount = Money::normalize($in['amount']);
        $subjects = array_filter([
            'orders' => ! empty($in['orderIds']),
            'booking' => ! empty($in['bookingId']),
            'membership' => ! empty($in['membershipId']),
        ]);
        if (count($subjects) !== 1) {
            throw ApiProblem::unprocessable('validation_failed', 'Provide exactly one of orderIds, bookingId, membershipId.', ['orderIds' => ['Provide exactly one of orderIds, bookingId, membershipId.']]);
        }

        return DB::transaction(function () use ($in, $amount, $staffId, $subjects, $online) {
            $intent = [];
            $subjectType = $subjectId = null;
            if (isset($subjects['orders'])) {
                $orders = $this->orders->lockOrders($in['orderIds']); // FIRST statement: lock, then read balances
                foreach ($in['orderIds'] as $oid) {
                    if (! isset($orders[Ids::normalize($oid)])) {
                        throw ApiProblem::notFound('not_found', 'Order not found.');
                    }
                    $online && Owns::order(Ids::normalize($oid));
                }
                $facilityIds = array_values(array_unique(array_map(fn ($o) => $o['facilityId'], $orders)));
                if (count($facilityIds) !== 1) {
                    throw ApiProblem::conflict('facility_mismatch', 'All orders of one online payment must belong to the same facility.');
                }
                $facilityId = $facilityIds[0];
                $online || $this->authorize($staffId, $facilityId);
                $paid = Ledger::paidByOrder(array_keys($orders));
                $reserved = Ledger::pendingByOrder(array_keys($orders)); // waiter collections awaiting confirmation reserve the balance
                $due = '0.0000';
                $allocs = [];
                foreach ($orders as $oid => $o) {
                    $balance = bcsub($o['total'], $paid[$oid], 4);
                    if (bccomp($balance, '0', 4) <= 0) {
                        throw ApiProblem::conflict('balance_changed', 'An order has no balance due.', ['orderId' => $oid, 'balanceDue' => '0.0000']);
                    }
                    if (bccomp($reserved[$oid], '0', 4) > 0) {
                        throw ApiProblem::conflict('pending_collection_exists', 'Money collected at the table is awaiting confirmation for this order.', ['orderId' => $oid, 'balanceDue' => $balance, 'pendingCollected' => $reserved[$oid]]);
                    }
                    $this->payments->assertPayable($o, $online);
                    $due = bcadd($due, $balance, 4);
                    $allocs[] = ['orderId' => $oid, 'amount' => $balance];
                }
                if (bccomp($amount, $due, 4) !== 0) {
                    throw ApiProblem::unprocessable('amount_mismatch', "Amount must equal the balance due ({$due}).", []);
                }
                $intent = ['allocations' => $allocs];
                $meta = ['orderNumbers' => array_values(array_map(fn ($o) => $o['number'], $orders))];
            } else {
                $subjectType = isset($subjects['booking']) ? 'BOOKING' : 'MEMBERSHIP';
                $subjectId = Ids::normalize((string) ($in['bookingId'] ?? $in['membershipId']));
                if ($online) {
                    $ownerCol = $subjectType === 'BOOKING' ? 'booking' : 'membership';
                    $owner = DB::table($ownerCol)->where('id', Ids::toBinary($subjectId))->value('customer_id');
                    $ownerId = $owner === null ? null : Ids::fromBinary($owner);
                    $subjectType === 'BOOKING' ? Owns::booking($ownerId) : Owns::membership($ownerId);
                }
                $subject = $this->subjects->resolve($subjectType, $subjectId)
                    ?? throw ApiProblem::unprocessable('payable_subject_unsupported', 'This node cannot take online payment for that '.strtolower($subjectType).'.');
                $facilityId = Ids::normalize($subject['facilityId']);
                $online || $this->authorize($staffId, $facilityId);
                if (bccomp($amount, Money::normalize($subject['amountDue']), 4) !== 0) {
                    throw ApiProblem::unprocessable('amount_mismatch', "Amount must equal the amount due ({$subject['amountDue']}).", []);
                }
                $meta = [strtolower($subjectType).'Id' => $subjectId];
            }

            $paymentId = Ids::uuid7();
            $reference = 'R007-'.strtoupper(str_replace('-', '', $paymentId));
            $init = $this->adapter->initialize($reference, $amount, 'NGN', $in['email'], $in['callbackUrl'] ?? null, $meta + ['paymentId' => $paymentId]);

            ['org' => $org, 'site' => $site] = Tenantless::facility($facilityId);
            DB::table('payment')->insert([
                'id' => Ids::toBinary($paymentId), 'organization_id' => Ids::toBinary($org), 'site_id' => Ids::toBinary($site),
                'facility_unit_id' => Ids::toBinary($facilityId), 'group_id' => Ids::toBinary($paymentId),
                'tender_type' => 'CARD', 'provider' => 'PAYSTACK', 'provider_reference' => $reference, 'status' => 'AUTHORIZING',
                'amount' => $amount, 'currency' => 'NGN', 'device_id' => Fmt::bin(RequestContext::deviceId()),
                'taken_by_staff_id' => Fmt::bin($staffId), 'customer_email' => $in['email'],
                'subject_type' => $subjectType, 'subject_id' => Fmt::bin($subjectId),
                'intent' => json_encode($intent, JSON_THROW_ON_ERROR), 'created_at' => Fmt::now(),
            ]);
            Audit::record('payment.paystack.initialize', 'Payment', $paymentId, new: ['amount' => $amount, 'reference' => $reference, 'intent' => $intent, 'subjectType' => $subjectType, 'subjectId' => $subjectId], facilityUnitId: $facilityId);

            return ['paymentId' => $paymentId, 'reference' => $reference, 'authorizationUrl' => $init['authorizationUrl'], 'accessCode' => $init['accessCode']];
        });
    }

    /** `GET /payments/paystack/verify/{reference}`: ask Paystack, capture if confirmed (idempotent with the webhook). */
    public function verify(string $reference, ?string $staffId): array
    {
        $row = DB::table('payment')->where('provider', 'PAYSTACK')->where('provider_reference', $reference)->first();
        if ($row === null) {
            throw ApiProblem::notFound('not_found', 'Unknown payment reference.');
        }
        $facility = Ids::fromBinary($row->facility_unit_id);
        if ($staffId === null) {
            Owns::payment($row); // customer: only their own booking / membership / ticket-order payment (404 otherwise)
        } elseif (Fmt::uuid($row->taken_by_staff_id) !== $staffId && ! $this->permissions->can($staffId, 'payment.view', Scope::facility($facility))) {
            throw ApiProblem::permissionDenied('payment.view');
        }
        $this->confirm($reference, null);

        return $this->presenter->one(DB::table('payment')->where('id', $row->id)->first());
    }

    /**
     * Webhook entry point (route has no bearer auth: the HMAC signature is the authentication).
     *
     * @return array{received: bool, duplicate: bool}
     */
    public function handleWebhook(string $rawBody, ?string $signature, ?string $ip): array
    {
        $allowed = config('payments.paystack.webhook_allowed_ips', []);
        if ($allowed !== [] && ! in_array((string) $ip, $allowed, true)) {
            $this->rejectWebhook('payment.webhook_ip_rejected', $rawBody, $ip);
        }
        if (! $this->adapter->verifySignature($rawBody, $signature)) {
            $this->rejectWebhook('payment.webhook_signature_invalid', $rawBody, $ip);
        }
        $event = $this->adapter->parseWebhook($rawBody);
        if ($event === null) {
            throw ApiProblem::badRequest('validation_failed', 'Unrecognised webhook body.');
        }
        // Cheap duplicate short-circuit (no provider call, no locks). The UNIQUE index below is the real guard.
        if ($this->eventKnown($event)) {
            return ['received' => true, 'duplicate' => true];
        }
        if ($event->type !== 'charge.success' || $event->reference === null) {
            // Not something we act on (transfers, refunds, subscriptions...): keep an audit copy and acknowledge.
            return ['received' => true, 'duplicate' => ! $this->recordEvent($event, null)];
        }

        return ['received' => true, 'duplicate' => $this->confirm($event->reference, $event)['duplicate']];
    }

    /**
     * @return array{duplicate: bool}
     */
    public function confirm(string $reference, ?WebhookEvent $event): array
    {
        $row = DB::table('payment')->where('provider', 'PAYSTACK')->where('provider_reference', $reference)->first();
        if ($row === null) { // a reference we never issued (another environment / spoof): store for audit, do nothing
            return ['duplicate' => $event !== null && ! $this->recordEvent($event, null)];
        }
        $tx = null;
        if (in_array($row->status, ['AUTHORIZING', 'INITIATED'], true)) {
            $tx = $this->adapter->verify($reference); // HTTP, outside any transaction of ours
        }

        return DB::transaction(function () use ($row, $event, $tx) {
            if ($event !== null && ! $this->recordEvent($event, $row->id)) {
                return ['duplicate' => true];
            }
            $p = DB::selectOne('SELECT * FROM payment WHERE id = ? FOR UPDATE', [$row->id]);
            if (! in_array($p->status, ['AUTHORIZING', 'INITIATED'], true) || $tx === null) {
                return ['duplicate' => false]; // already final (captured / failed / ...): nothing to do
            }
            if ($tx->status === VerifiedTransaction::SUCCESS) {
                if (bccomp($tx->amount, Money::normalize((string) $p->amount), 4) !== 0 || $tx->currency !== 'NGN') {
                    DB::table('payment')->where('id', $p->id)->update(['status' => 'FAILED', 'failure_reason' => 'provider_amount_mismatch', 'row_version' => DB::raw('row_version + 1')]);
                    Audit::securityEvent('payment.provider_amount_mismatch', 'CRITICAL', null, null, ['paymentId' => Ids::fromBinary($p->id), 'expected' => Money::normalize((string) $p->amount), 'confirmed' => $tx->amount, 'currency' => $tx->currency]);
                    Audit::record('payment.failed', 'Payment', Ids::fromBinary($p->id), new: ['reason' => 'provider_amount_mismatch'], organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), facilityUnitId: Ids::fromBinary($p->facility_unit_id));

                    return ['duplicate' => false];
                }
                try {
                    $this->capture($p, $tx);
                } catch (CollectionCaptureConflict $e) {
                    // Real money arrived for a collection whose order can no longer take it (e.g. an online payment landed first): leave it
                    // AUTHORIZING for a human decision (refund) instead of failing the webhook forever.
                    Audit::securityEvent('payment.collection_capture_conflict', 'CRITICAL', null, null, ['paymentId' => Ids::fromBinary($p->id), 'amount' => Money::normalize((string) $p->amount), 'detail' => $e->getMessage()]);
                }
            } elseif ($tx->status === VerifiedTransaction::FAILED) {
                DB::table('payment')->where('id', $p->id)->update(['status' => 'FAILED', 'failure_reason' => mb_substr((string) $tx->gatewayResponse, 0, 255) ?: 'provider_failed', 'row_version' => DB::raw('row_version + 1')]);
                Audit::record('payment.failed', 'Payment', Ids::fromBinary($p->id), new: ['reason' => $tx->gatewayResponse], organizationId: Ids::fromBinary($p->organization_id), siteId: Ids::fromBinary($p->site_id), facilityUnitId: Ids::fromBinary($p->facility_unit_id));
            }

            return ['duplicate' => false];
        }, 3);
    }

    /** Provider-confirmed money arrives: allocate to the intended orders (capped at what is still owed), receipt, effects. */
    private function capture(object $p, VerifiedTransaction $tx): void
    {
        $paymentId = Ids::fromBinary($p->id);
        $facilityId = Ids::fromBinary($p->facility_unit_id);
        $orgId = Ids::fromBinary($p->organization_id);
        $siteId = Ids::fromBinary($p->site_id);
        $groupId = Ids::fromBinary($p->group_id);
        $amount = Money::normalize((string) $p->amount);
        $staffId = Fmt::uuid($p->taken_by_staff_id);
        $intent = json_decode((string) $p->intent, true) ?: [];
        $now = Fmt::now();

        $slices = [];
        $orders = [];
        $paidBefore = [];
        $unallocated = '0.0000';
        $receiptId = null;
        $reserved = DB::table('payment_allocation')->where('payment_id', $p->id)->orderBy('id')->get(['order_id', 'amount']); // waiter collections pre-allocate
        if ($reserved->isNotEmpty()) {
            $orders = $this->orders->lockOrders($reserved->map(fn ($a) => Ids::fromBinary($a->order_id))->all());
            $paidBefore = Ledger::paidByOrder(array_keys($orders));
            foreach ($reserved as $a) {
                $oid = Ids::fromBinary($a->order_id);
                $take = Money::normalize((string) $a->amount);
                if (! isset($orders[$oid]) || in_array($orders[$oid]['status'], ['VOIDED', 'PENDING_APPROVAL'], true) || bccomp(bcadd($paidBefore[$oid], $take, 4), $orders[$oid]['total'], 4) > 0) {
                    throw new CollectionCaptureConflict("Order {$oid} can no longer absorb the collected amount.");
                }
                $slices[] = ['orderId' => $oid, 'amount' => $take];
            }
        } elseif (! empty($intent['allocations'])) {
            $orders = $this->orders->lockOrders(array_map(fn ($a) => $a['orderId'], $intent['allocations'])); // payment -> orders (same as reversal)
            $paidBefore = Ledger::paidByOrder(array_keys($orders));
            $left = $amount;
            foreach ($intent['allocations'] as $a) {
                $o = $orders[$a['orderId']] ?? null;
                $balance = $o === null || in_array($o['status'], ['VOIDED', 'PENDING_APPROVAL'], true) ? '0.0000' : bcsub($o['total'], $paidBefore[$a['orderId']], 4);
                $take = self::min3($a['amount'], $balance, $left);
                if (bccomp($take, '0', 4) > 0) {
                    $slices[] = ['orderId' => $a['orderId'], 'amount' => $take];
                    $left = bcsub($left, $take, 4);
                }
            }
            $unallocated = $left;
        }

        if ($slices !== []) {
            $allocatedNow = [];
            $balanceDue = '0.0000';
            $receiptOrders = [];
            foreach ($slices as $s) {
                $allocatedNow[$s['orderId']] = $s['amount'];
                $receiptOrders[] = $orders[$s['orderId']];
                $balanceDue = bcadd($balanceDue, bcsub($orders[$s['orderId']]['total'], bcadd($paidBefore[$s['orderId']], $s['amount'], 4), 4), 4);
            }
            $paidAmount = bcsub($amount, $unallocated, 4);
            $receipt = $this->receipts->issue([
                'organizationId' => $orgId, 'siteId' => $siteId, 'facilityId' => $facilityId, 'groupId' => $groupId, 'staffId' => $staffId,
                'deviceId' => Fmt::uuid($p->device_id), 'orders' => $receiptOrders, 'details' => $this->orders->details(array_keys($allocatedNow)),
                'tenders' => [['tenderType' => $p->tender_type, 'amount' => $paidAmount, 'reference' => $p->provider_reference, 'tendered' => null, 'changeGiven' => '0.0000']],
                'amountPaid' => $paidAmount, 'balanceDue' => $balanceDue,
            ]);
            $receiptId = $receipt['id'];
            if ($reserved->isEmpty()) {
                foreach ($slices as $s) {
                    DB::table('payment_allocation')->insert([
                        'id' => Ids::toBinary(Ids::uuid7()), 'payment_id' => $p->id, 'order_id' => Ids::toBinary($s['orderId']), 'amount' => $s['amount'],
                    ]);
                }
            }
        }

        DB::table('payment')->where('id', $p->id)->update([
            'status' => 'CAPTURED', 'captured_at' => $now, 'provider_txn_id' => $tx->providerTransactionId, 'receipt_id' => Fmt::bin($receiptId),
            'unallocated_amount' => $unallocated, 'row_version' => DB::raw('row_version + 1'),
        ]);

        if ($slices !== []) {
            $allocatedNow = [];
            foreach ($slices as $s) {
                $allocatedNow[$s['orderId']] = $s['amount'];
            }
            $this->effects->syncOrders($orders, $paidBefore, $allocatedNow, $groupId);
        }
        $this->effects->captured([[
            'id' => $paymentId, 'tenderType' => $p->tender_type, 'amount' => $amount, 'reference' => $p->provider_reference, 'tendered' => null, 'changeGiven' => '0.0000',
            'cashSessionId' => null, 'receiptId' => $receiptId, 'takenByStaffId' => $staffId, 'allocations' => $slices,
        ]], [
            'organizationId' => $orgId, 'siteId' => $siteId, 'facilityId' => $facilityId, 'groupId' => $groupId, 'provider' => 'PAYSTACK', 'staffId' => null,
            'subjectType' => $p->subject_type, 'subjectId' => Fmt::uuid($p->subject_id), 'capturedAt' => Fmt::iso($now),
        ], 'payment.capture.online');

        if (bccomp($unallocated, '0', 4) > 0) {
            // Real money arrived for something already settled another way: needs a refund decision by a human.
            Audit::securityEvent('payment.online_overpayment', 'WARNING', null, null, ['paymentId' => $paymentId, 'unallocated' => $unallocated]);
        }
    }

    private static function assertCallbackAllowed(?string $url): void
    {
        $allowed = (array) config('customer.allowed_callback_hosts', []);
        if ($url === null || $allowed === []) {
            return;
        }
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), array_map('strtolower', $allowed), true)) {
            throw ApiProblem::unprocessable('validation_failed', 'callbackUrl host is not allowed.', ['callbackUrl' => ['host not allowed']]);
        }
    }

    private function authorize(string $staffId, string $facilityId): void
    {
        if (! $this->permissions->can($staffId, 'payment.take', Scope::facility($facilityId))) {
            throw ApiProblem::permissionDenied('payment.take');
        }
    }

    private function eventKnown(WebhookEvent $e): bool
    {
        return DB::table('provider_event')->where('provider', 'paystack')->where('provider_event_id', $e->eventId)->exists();
    }

    /** @return bool true if this call inserted the event (first sighting), false if it was already recorded (duplicate) */
    private function recordEvent(WebhookEvent $e, ?string $paymentBin): bool
    {
        try {
            DB::table('provider_event')->insert([
                'id' => Ids::toBinary(Ids::uuid7()), 'provider' => 'paystack', 'provider_event_id' => $e->eventId, 'event_type' => substr($e->type, 0, 64),
                'payload_hash' => hash('sha256', json_encode($e->payload, JSON_UNESCAPED_SLASHES)), 'payload' => json_encode($e->payload, JSON_UNESCAPED_SLASHES),
                'payment_id' => $paymentBin, 'received_at' => Fmt::now(),
            ]);

            return true;
        } catch (QueryException $ex) {
            if (($ex->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw $ex;
        }
    }

    /** Failed authentication: a security event (never the body, signature or key) and a bare 401. */
    private function rejectWebhook(string $type, string $rawBody, ?string $ip): never
    {
        Audit::securityEvent($type, 'WARNING', null, $ip, ['provider' => 'paystack', 'bodySha256' => hash('sha256', $rawBody), 'bytes' => strlen($rawBody)]);
        throw ApiProblem::unauthenticated('unauthenticated', 'Invalid webhook signature.');
    }

    /** min of three decimal strings */
    private static function min3(string $a, string $b, string $c): string
    {
        $m = $a;
        foreach ([$b, $c] as $x) {
            if (bccomp($x, $m, 4) < 0) {
                $m = $x;
            }
        }

        return bcadd($m, '0', 4);
    }
}
