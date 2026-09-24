<?php

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Payments\Services\PaymentTerminalService;
use App\Domain\Payments\Support\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Payment terminal registry. Writes need `device.manage`; reads also `payment.collect` (waiters pick their terminal). */
class TerminalController
{
    public function __construct(private readonly PaymentTerminalService $terminals, private readonly PermissionChecker $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('payment_terminal')->orderBy('label');
        if (is_string($f = $request->query('facilityId')) && Ids::isUuid($f)) {
            $this->requireRead(Ids::normalize($f));
            $q->where('facility_unit_id', Ids::toBinary($f));
        } elseif (! $this->permissions->can(RequestContext::staffId(), 'device.manage')) {
            throw ApiProblem::badRequest('validation_failed', 'facilityId is required.');
        }
        if (is_string($s = $request->query('status')) && $s !== '') {
            $q->whereIn('status', array_map('strtoupper', explode(',', $s)));
        }

        return response()->json(['items' => $q->get()->map(fn ($t) => $this->terminals->present($t))->all()]);
    }

    public function show(string $terminalId): JsonResponse
    {
        $t = Ids::isUuid($terminalId) ? DB::table('payment_terminal')->where('id', Ids::toBinary($terminalId))->first() : null;
        $t ?? throw ApiProblem::notFound('not_found', 'Terminal not found.');
        $this->requireRead(Ids::fromBinary($t->facility_unit_id));

        return response()->json($this->terminals->present($t));
    }

    public function store(Request $request): JsonResponse
    {
        $in = $request->validate([
            'id' => ['nullable', function ($a, $v, $fail) {
                Fmt::isUuid7($v) || $fail('The id must be a UUIDv7.');
            }],
            'facilityId' => ['required', 'uuid'], 'provider' => ['nullable', 'in:MANUAL_BANK,PAYSTACK_TERMINAL'], 'label' => ['required', 'string', 'min:1', 'max:120'],
            'serial' => ['nullable', 'string', 'max:120'], 'assignedDeviceId' => ['nullable', 'uuid'], 'assignedStaffId' => ['nullable', 'uuid'],
        ]);
        $this->requireManage(Ids::normalize($in['facilityId']));

        return response()->json($this->terminals->create($in), 201);
    }

    public function update(Request $request, string $terminalId): JsonResponse
    {
        $t = Ids::isUuid($terminalId) ? DB::table('payment_terminal')->where('id', Ids::toBinary($terminalId))->first() : null;
        $t ?? throw ApiProblem::notFound('not_found', 'Terminal not found.');
        $this->requireManage(Ids::fromBinary($t->facility_unit_id));
        $in = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:120'], 'serial' => ['sometimes', 'nullable', 'string', 'max:120'], 'status' => ['sometimes', 'in:ACTIVE,INACTIVE,RETIRED'],
            'assignedDeviceId' => ['sometimes', 'nullable', 'uuid'], 'assignedStaffId' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json($this->terminals->update(Ids::normalize($terminalId), $in));
    }

    private function requireManage(string $facilityId): void
    {
        if (! $this->permissions->can(RequestContext::staffId(), 'device.manage', Scope::facility($facilityId))) {
            throw ApiProblem::permissionDenied('device.manage');
        }
    }

    private function requireRead(string $facilityId): void
    {
        $s = RequestContext::staffId();
        if (! $this->permissions->can($s, 'device.manage', Scope::facility($facilityId)) && ! $this->permissions->can($s, 'payment.collect', Scope::facility($facilityId))) {
            throw ApiProblem::permissionDenied('payment.collect');
        }
    }
}
