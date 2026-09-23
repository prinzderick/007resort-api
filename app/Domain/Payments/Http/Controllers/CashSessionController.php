<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Services\CashSessionService;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashSessionController
{
    public function __construct(private readonly CashSessionService $sessions, private readonly PermissionChecker $permissions) {}

    /** POST /cash-sessions */
    public function open(Request $request): JsonResponse
    {
        $in = $request->validate(['facilityId' => ['required', 'uuid'], 'openingFloat' => ['required', self::money()]]);

        return response()->json($this->sessions->open($in['facilityId'], $in['openingFloat'], $this->staffId()), 201);
    }

    /** GET /cash-sessions */
    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffId();
        $f = (array) $request->query('filter', []);
        $q = DB::table('cash_session');
        if (! empty($f['facilityId']) && Ids::isUuid($f['facilityId'])) {
            $this->requireAt($staff, 'cash_session.view', $f['facilityId']);
            $q->where('facility_unit_id', Ids::toBinary($f['facilityId']));
            if (! empty($f['staffId']) && Ids::isUuid($f['staffId'])) {
                $q->where('staff_id', Ids::toBinary($f['staffId']));
            }
        } else {
            $q->where('staff_id', Ids::toBinary($staff)); // no facility scope: only your own sessions
        }
        if (! empty($f['status'])) {
            $q->where('status', strtoupper((string) $f['status']));
        }
        $page = CursorPage::paginate($q, $request, 'id', 'desc');

        return response()->json($page->toArray(fn ($s) => $this->sessions->present($s)));
    }

    /** GET /cash-sessions/{id} */
    public function show(string $cashSessionId): JsonResponse
    {
        $s = DB::table('cash_session')->where('id', Ids::toBinary($cashSessionId))->first() ?? throw ApiProblem::notFound('not_found', 'Cash session not found.');
        if (Ids::fromBinary($s->staff_id) !== $this->staffId()) {
            $this->requireAt($this->staffId(), 'cash_session.view', Ids::fromBinary($s->facility_unit_id));
        }

        return response()->json($this->sessions->present($s));
    }

    /** POST /cash-sessions/{id}/close */
    public function close(Request $request, string $cashSessionId): JsonResponse
    {
        $in = $request->validate(['countedCash' => ['required', self::money()], 'note' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->sessions->close(Ids::normalize($cashSessionId), $in['countedCash'], $in['note'] ?? null, $this->staffId()));
    }

    /** POST /cash-sessions/{id}/movements (additive to contract v1): paid-in / paid-out / safe drop */
    public function movement(Request $request, string $cashSessionId): JsonResponse
    {
        $in = $request->validate([
            'kind' => ['required', Rule::in(['PAID_IN', 'PAID_OUT', 'DROP'])],
            'amount' => ['required', self::money(true)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return response()->json($this->sessions->recordMovement(Ids::normalize($cashSessionId), $in['kind'], $in['amount'], $in['reason'], $this->staffId()), 201);
    }

    private static function money(bool $positive = false): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail) use ($positive): void {
            if (! Money::isValid($value) || bccomp((string) $value, '0', 4) < 0 || ($positive && bccomp((string) $value, '0', 4) === 0)) {
                $fail("The {$attr} must be a ".($positive ? 'positive' : 'non-negative').' decimal string with at most 4 decimal places.');
            }
        };
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
