<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Membership\Models\MembershipPlan;
use App\Domain\Membership\Services\PlanService;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Paged;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController
{
    public function __construct(private readonly PlanService $plans) {}

    /** GET /memberships/plans?active=true */
    public function index(Request $request): JsonResponse
    {
        $q = MembershipPlan::query()->with('coverage')->where('organization_id', Tenant::organizationId());
        if ($request->query('active') !== null) {
            $q->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }
        $page = CursorPage::paginate($q, $request, 'id', 'asc');

        return response()->json(Paged::of($page, fn (MembershipPlan $p) => $p->toApi()));
    }

    /** POST /memberships/plans */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            ...$this->rules(required: true),
        ]);

        return response()->json($this->plans->create($d)->toApi(), 201);
    }

    /** PATCH /memberships/plans/{plan} */
    public function update(Request $request, string $plan): JsonResponse
    {
        $d = $request->validate($this->rules(required: false));
        if ($d === []) {
            throw ApiProblem::badRequest('empty_update', 'Nothing to update.');
        }

        return response()->json($this->plans->update($plan, $d)->toApi());
    }

    private function rules(bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';
        $money = ['regex:/^\d{1,15}(\.\d{1,4})?$/'];

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => [$req, 'string', ...$money],
            'durationDays' => [$req, 'integer', 'min:1', 'max:3650'],
            'visitLimit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'guestAllowance' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'memberDiscountPercent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'bookingAdvanceDays' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'bookingPrivileges' => ['sometimes', 'nullable', 'array'],
            'gracePeriodDays' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'renewalNoticeDays' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'propertyWide' => ['sometimes', 'boolean'],
            'facilityIds' => ['sometimes', 'array'],
            'facilityIds.*' => ['uuid'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
