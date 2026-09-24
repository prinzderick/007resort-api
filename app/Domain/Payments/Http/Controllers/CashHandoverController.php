<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Services\CashHandoverService;
use App\Domain\Payments\Services\CollectionPolicyService;
use App\Domain\Payments\Support\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Cash handover, cash-in-hand position and collection policy endpoints. */
class CashHandoverController
{
    public function __construct(
        private readonly CashHandoverService $handovers,
        private readonly CollectionPolicyService $policy,
        private readonly PermissionChecker $permissions,
    ) {}

    public function declare(Request $request): JsonResponse
    {
        $in = $request->validate([
            'id' => ['nullable', function ($a, $v, $fail) {
                Fmt::isUuid7($v) || $fail('The id must be a UUIDv7.');
            }],
            'facilityId' => ['nullable', 'uuid'], 'declaredAmount' => ['required', self::positiveMoney()], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $r = $this->handovers->declare($in, $this->staff());

        return response()->json($r['body'], $r['replayed'] ? 200 : 201, $r['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    public function receive(Request $request, string $handoverId): JsonResponse
    {
        $in = $request->validate(['countedAmount' => ['required', function ($a, $v, $fail) {
            (Money::isValid($v) && bccomp((string) $v, '0', 4) >= 0) || $fail('The countedAmount must be a non-negative decimal string.');
        }], 'note' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->handovers->receive($handoverId, $in, $this->staff()));
    }

    public function signoff(Request $request, string $handoverId): JsonResponse
    {
        $in = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->handovers->signoff($handoverId, $in['note'] ?? null, $this->staff()));
    }

    public function index(Request $request): JsonResponse
    {
        $me = $this->staff();
        $q = DB::table('cash_handover');
        $facility = $request->query('facilityId');
        $waiter = $request->query('waiterId');
        if (is_string($facility) && Ids::isUuid($facility)) {
            if (! $this->permissions->can($me, 'cash_handover.view', Scope::facility($facility)) && $waiter !== $me) {
                throw ApiProblem::permissionDenied('cash_handover.view');
            }
            $q->where('facility_unit_id', Ids::toBinary($facility));
        } elseif (! is_string($waiter) || $waiter !== $me) {
            if (! $this->permissions->can($me, 'cash_handover.view')) {
                $q->where('waiter_staff_id', Ids::toBinary($me)); // no view permission: only your own
            }
        }
        if (is_string($waiter) && Ids::isUuid($waiter)) {
            $q->where('waiter_staff_id', Ids::toBinary($waiter));
        }
        if (is_string($s = $request->query('status')) && $s !== '') {
            $q->whereIn('status', array_map('strtoupper', explode(',', $s)));
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json($page->toArray(fn ($h) => $this->handovers->present($h)));
    }

    public function show(string $handoverId): JsonResponse
    {
        $h = Ids::isUuid($handoverId) ? DB::table('cash_handover')->where('id', Ids::toBinary($handoverId))->first() : null;
        if ($h === null) {
            throw ApiProblem::notFound('not_found', 'Handover not found.');
        }
        $me = $this->staff();
        if (Ids::fromBinary($h->waiter_staff_id) !== $me && ! $this->permissions->can($me, 'cash_handover.view', Scope::facility(Ids::fromBinary($h->facility_unit_id)))) {
            throw ApiProblem::permissionDenied('cash_handover.view');
        }

        return response()->json($this->handovers->present($h));
    }

    public function cashInHand(Request $request, string $staffId): JsonResponse
    {
        $staffId = $this->targetStaff($staffId);
        $me = $this->staff();
        if ($staffId !== $me && ! $this->permissions->can($me, 'cash_handover.view')) {
            throw ApiProblem::permissionDenied('cash_handover.view');
        }
        $facility = $request->query('facilityId');

        return response()->json($this->handovers->position($staffId, is_string($facility) && Ids::isUuid($facility) ? Ids::normalize($facility) : null));
    }

    public function showPolicy(Request $request, string $staffId): JsonResponse
    {
        $staffId = $this->targetStaff($staffId);
        $this->policy->assertCanView($staffId);
        $f = $request->query('facilityId');
        $facility = is_string($f) && Ids::isUuid($f) ? Ids::normalize($f) : $this->policy->defaultFacility($staffId);

        return response()->json($this->policy->effective($staffId, $facility));
    }

    public function updatePolicy(Request $request, string $staffId): JsonResponse
    {
        $staffId = $this->targetStaff($staffId);
        $in = $request->validate([
            'cashHolding' => ['nullable', 'in:INHERIT,ALLOW,DENY'],
            'cashLimit' => ['nullable', function ($a, $v, $fail) {
                (Money::isValid($v) && bccomp((string) $v, '0', 4) >= 0) || $fail('The cashLimit must be a non-negative decimal string or null.');
            }],
        ]);
        $in = array_intersect_key($in, $request->json()->all()); // only what the client sent (null cashLimit clears the personal limit)
        if (array_key_exists('cashHolding', $in) && $in['cashHolding'] === null) {
            unset($in['cashHolding']);
        }

        return response()->json($this->policy->update($staffId, $in));
    }

    private function targetStaff(string $id): string
    {
        if ($id === 'me') {
            return $this->staff();
        }

        return Ids::isUuid($id) ? Ids::normalize($id) : throw ApiProblem::notFound();
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

    private function staff(): string
    {
        return RequestContext::staffId() ?? throw ApiProblem::unauthenticated();
    }
}
