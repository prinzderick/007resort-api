<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Services\RoleAssignmentService;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleAssignmentController
{
    public function __construct(private readonly RoleAssignmentService $service) {}

    public function index(Request $request, string $staffId): JsonResponse
    {
        $staff = $this->staff($staffId);
        $page = CursorPage::paginate(RoleAssignment::query()->where('staff_id', $staff->id), $request);

        return response()->json($page->toArray(fn (RoleAssignment $a) => $this->service->present($a)));
    }

    /** 201 with the new assignment; 200 with the existing one if the same (staff, role, scope) is already active (idempotent). */
    public function store(Request $request, string $staffId): JsonResponse
    {
        $staff = $this->staff($staffId);
        $d = $request->validate([
            'roleId' => ['required', 'uuid'],
            'scopeType' => ['required', 'in:ORGANIZATION,SITE,FACILITY'],
            'scopeId' => ['required', 'uuid'],
        ]);
        [$a, $created] = $this->service->grant($staff, $d['roleId'], $d['scopeType'], $d['scopeId']);

        return response()->json($this->service->present($a), $created ? 201 : 200);
    }

    public function destroy(string $staffId, string $assignmentId): Response
    {
        $staff = $this->staff($staffId);
        if (! Ids::isUuid($assignmentId)) {
            throw ApiProblem::notFound('not_found', 'Role assignment was not found.');
        }
        $this->service->revoke($staff, $assignmentId);

        return response()->noContent();
    }

    private function staff(string $staffId): Staff
    {
        return Ids::isUuid($staffId)
            ? (Staff::query()->where('site_id', RequestContext::siteId())->find($staffId) ?? throw ApiProblem::notFound('not_found', 'Staff member was not found.'))
            : throw ApiProblem::notFound('not_found', 'Staff member was not found.');
    }
}
