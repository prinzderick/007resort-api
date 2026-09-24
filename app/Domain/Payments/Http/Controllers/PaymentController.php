<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Services\PaymentPresenter;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payments\Services\PaystackService;
use App\Domain\Payments\Services\RefundService;
use App\Domain\Payments\Support\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentPresenter $presenter,
        private readonly RefundService $refunds,
        private readonly PaystackService $paystack,
        private readonly PermissionChecker $permissions,
    ) {}

    /** POST /payments */
    public function store(Request $request): JsonResponse
    {
        $in = $request->validate([
            'facilityId' => ['required', 'uuid'],
            'cashSessionId' => ['nullable', 'uuid'],
            'tabId' => ['nullable', 'uuid'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.orderId' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', self::positiveMoney()],
            'customerName' => ['nullable', 'string', 'max:160'],
            'clientCreatedAt' => ['nullable', 'date'],
        ] + self::tenderRules());
        $this->assertNoDuplicateTenderIds($in['tenders']);
        foreach ($in['tenders'] as $i => $t) {
            $in['tenders'][$i]['fingerprint'] = PaymentService::fingerprint($in, $t, false);
        }
        $r = $this->payments->create($in, $this->staffId());

        return $this->replayable($r);
    }

    /** POST /tabs/{tabId}/settle */
    public function settleTab(Request $request, string $tabId): JsonResponse
    {
        $in = $request->validate(['cashSessionId' => ['nullable', 'uuid']] + self::tenderRules());
        $this->assertNoDuplicateTenderIds($in['tenders']);
        $ctx = ['tabId' => $tabId, 'allocations' => [], 'facilityId' => null];
        foreach ($in['tenders'] as $i => $t) {
            $in['tenders'][$i]['fingerprint'] = PaymentService::fingerprint($ctx, $t, true);
        }
        $r = $this->payments->settleTab(Ids::normalize($tabId), $in, $this->staffId());

        return response()->json($r['body'], 200, $r['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    /** GET /payments */
    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffId();
        $f = (array) $request->query('filter', []);
        $q = DB::table('payment');
        if (! empty($f['facilityId']) && Ids::isUuid($f['facilityId'])) {
            $this->requireAt($staff, 'payment.view', $f['facilityId']);
            $q->where('facility_unit_id', Ids::toBinary($f['facilityId']));
        } elseif (! $this->holdsSiteWide($staff, 'payment.view')) {
            $q->where('taken_by_staff_id', Ids::toBinary($staff)); // without a facility scope you only see your own takings...
        } // ...unless you hold payment.view site-wide (owner/accountant): then no facility filter = the whole property
        if (! empty($f['cashSessionId']) && Ids::isUuid($f['cashSessionId'])) {
            $q->where('cash_session_id', Ids::toBinary($f['cashSessionId']));
        }
        if (! empty($f['status'])) {
            $q->where('status', strtoupper((string) $f['status']));
        }
        if (! empty($f['tenderType'])) {
            $q->where('tender_type', strtoupper((string) $f['tenderType']));
        }
        // filter[from] (inclusive) / filter[to] (exclusive; a bare YYYY-MM-DD `to` includes that whole UTC day) on created_at
        $range = validator($f, ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']])->validate();
        if (! empty($range['from'])) {
            $q->where('created_at', '>=', CarbonImmutable::parse($range['from'], 'UTC')->utc()->format('Y-m-d H:i:s.u'));
        }
        if (! empty($range['to'])) {
            $to = CarbonImmutable::parse($range['to'], 'UTC')->utc();
            $q->where('created_at', '<', ($this->bareDate($range['to']) ? $to->addDay() : $to)->format('Y-m-d H:i:s.u'));
        }
        if (! empty($f['groupId']) && Ids::isUuid($f['groupId'])) {
            $q->where('group_id', Ids::toBinary($f['groupId']));
        }
        if (! empty($f['orderId']) && Ids::isUuid($f['orderId'])) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('payment_allocation as pa')->whereColumn('pa.payment_id', 'payment.id')->where('pa.order_id', Ids::toBinary($f['orderId'])));
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json(['items' => $this->presenter->many($page->items->all()), 'nextCursor' => $page->nextCursor]);
    }

    /** GET /payments/{paymentId} */
    public function show(string $paymentId): JsonResponse
    {
        $row = DB::table('payment')->where('id', Ids::toBinary($paymentId))->first() ?? throw ApiProblem::notFound('not_found', 'Payment not found.');
        if (Fmt::uuid($row->taken_by_staff_id) !== $this->staffId()) {
            $this->requireAt($this->staffId(), 'payment.view', Ids::fromBinary($row->facility_unit_id));
        }

        return response()->json($this->presenter->one($row));
    }

    /** POST /payments/{paymentId}/refund */
    public function refund(Request $request, string $paymentId): JsonResponse
    {
        $in = $request->validate([
            'amount' => ['required', self::positiveMoney()],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'tenderType' => ['nullable', Rule::in(['CASH', 'CARD', 'TRANSFER', 'POS_TERMINAL'])],
        ]);
        $r = $this->refunds->refund(Ids::normalize($paymentId), $in, $this->staffId());

        return response()->json($r['body'], $r['status']);
    }

    /** POST /payments/{paymentId}/reversal */
    public function reverse(Request $request, string $paymentId): JsonResponse
    {
        $in = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $r = $this->refunds->reverse(Ids::normalize($paymentId), $in, $this->staffId());

        return response()->json($r['body'], $r['status']);
    }

    /** POST /payments/paystack/initialize */
    public function paystackInitialize(Request $request): JsonResponse
    {
        $in = $request->validate([
            'orderIds' => ['nullable', 'array', 'min:1', 'max:50'],
            'orderIds.*' => ['uuid', 'distinct'],
            'bookingId' => ['nullable', 'uuid'],
            'membershipId' => ['nullable', 'uuid'],
            'amount' => ['required', self::positiveMoney()],
            'email' => ['required', 'email', 'max:190'],
            'callbackUrl' => ['nullable', 'url', 'max:500'],
        ]);

        return response()->json($this->paystack->initialize($in, $this->staffId()), 201);
    }

    /** GET /payments/paystack/verify/{reference} */
    public function paystackVerify(string $reference): JsonResponse
    {
        return response()->json($this->paystack->verify($reference, $this->staffId()));
    }

    // ----------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function tenderRules(): array
    {
        return [
            'tenders' => ['required', 'array', 'min:1', 'max:10'],
            'tenders.*.tenderType' => ['required', Rule::in(['CASH', 'CARD', 'TRANSFER', 'POS_TERMINAL'])],
            'tenders.*.amount' => ['required', self::positiveMoney()],
            // POS terminal RRN / bank transfer reference are the audit trail for money we cannot see in the drawer.
            'tenders.*.reference' => ['nullable', 'string', 'max:128', 'required_if:tenders.*.tenderType,POS_TERMINAL,TRANSFER'],
            'tenders.*.tendered' => ['nullable', self::positiveMoney()],
            'tenders.*.id' => ['nullable', function ($attr, $value, $fail) {
                if (! Fmt::isUuid7($value)) {
                    $fail('The id must be a UUIDv7.');
                }
            }],
            'tenders.*.clientCreatedAt' => ['nullable', 'date'],
        ];
    }

    private static function positiveMoney(): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail): void {
            if (! Money::isValid($value)) {
                $fail('The :attribute must be a decimal string with at most 4 decimal places.');
            } elseif (bccomp((string) $value, '0', 4) <= 0) {
                $fail('The :attribute must be greater than zero.');
            }
        };
    }

    /** @param list<array<string, mixed>> $tenders */
    private function assertNoDuplicateTenderIds(array $tenders): void
    {
        $ids = array_filter(array_map(fn ($t) => isset($t['id']) ? strtolower($t['id']) : null, $tenders));
        if (count($ids) !== count(array_unique($ids))) {
            throw ApiProblem::unprocessable('validation_failed', 'Tender ids must be unique.', ['tenders' => ['Tender ids must be unique.']]);
        }
    }

    /** @param array{body: array<string, mixed>, replayed: bool} $r */
    private function replayable(array $r): JsonResponse
    {
        return response()->json($r['body'], 201, $r['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    private function bareDate(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    }

    /** True when the permission is held at SITE/ORGANIZATION scope (property-wide finance roles). */
    private function holdsSiteWide(string $staffId, string $permission): bool
    {
        $row = DB::table('staff')->where('id', Ids::toBinary($staffId))->first(['site_id']); // the caller's own site
        $site = $row ? Ids::fromBinary($row->site_id) : null;

        return $site !== null && $this->permissions->can($staffId, $permission, Scope::site($site));
    }

    private function requireAt(string $staffId, string $permission, string $facilityId): void
    {
        if (! $this->permissions->can($staffId, $permission, Scope::facility($facilityId))) {
            throw ApiProblem::permissionDenied($permission);
        }
    }

    private function staffId(): string
    {
        return RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
    }
}
