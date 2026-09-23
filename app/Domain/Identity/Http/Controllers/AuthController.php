<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\AuthSession;
use App\Domain\Identity\Models\UserAccount;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Identity\Services\StaffAuthService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AuthController
{
    public function __construct(private readonly StaffAuthService $auth, private readonly PermissionChecker $permissions) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255', 'required_without:pin'],
            'pin' => ['nullable', 'string', 'max:32', 'required_without:password'],
            'deviceId' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->auth->login($data['username'], $data, $request->ip(), $request->userAgent()));
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refreshToken' => ['required', 'string', 'max:255']]);

        return response()->json($this->auth->refresh($data['refreshToken'], $request->ip(), $request->userAgent()));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($this->currentSession($request));

        return response()->noContent();
    }

    public function stepUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:255', 'required_without:pin'],
            'pin' => ['nullable', 'string', 'max:32', 'required_without:password'],
        ]);
        /** @var UserAccount $account */
        $account = $request->user();

        return response()->json($this->auth->stepUp($account, $this->currentSession($request), $data, $request->ip()));
    }

    /** Own session: always allowed. Any other session: requires `session.revoke`. */
    public function revoke(Request $request, string $id): Response
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('session_not_found', 'Session was not found.');
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $id = Ids::normalize($id);

        if ($id !== RequestContext::sessionId() && ! $this->permissions->can(RequestContext::staffId(), 'session.revoke')) {
            throw ApiProblem::forbidden('permission_denied', 'Missing permission: session.revoke.', ['permission' => 'session.revoke']);
        }
        $this->auth->revokeSession($id, $data['reason'] ?? null);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var UserAccount $account */
        $account = $request->user();
        $staff = $account->staff;
        $grants = $this->permissions->effective($staff->id);

        $assignments = DB::table('role_assignment as ra')
            ->join('role as r', 'r.id', '=', 'ra.role_id')
            ->where('ra.staff_id', Ids::toBinary($staff->id))->where('ra.is_active', 1)->whereNull('ra.deleted_at')
            ->get(['ra.id', 'r.code', 'r.name', 'ra.scope_level', 'ra.organization_id', 'ra.site_id', 'ra.facility_unit_id'])
            ->map(fn ($r) => [
                'id' => Ids::fromBinary($r->id), 'roleCode' => $r->code, 'roleName' => $r->name, 'scopeLevel' => $r->scope_level,
                'organizationId' => $r->organization_id ? Ids::fromBinary($r->organization_id) : null,
                'siteId' => $r->site_id ? Ids::fromBinary($r->site_id) : null,
                'facilityUnitId' => $r->facility_unit_id ? Ids::fromBinary($r->facility_unit_id) : null,
            ])->values();

        return response()->json([
            'staff' => $staff->toApi(),
            'username' => $account->username,
            'sessionId' => RequestContext::sessionId(),
            'deviceId' => RequestContext::deviceId(),
            'permissions' => $grants->pluck('permission')->unique()->sort()->values(),
            'grants' => $grants->map(fn ($g) => [
                'permission' => $g->permission, 'requiresApproval' => $g->requiresApproval, 'scopeLevel' => $g->scopeLevel,
                'organizationId' => $g->organizationId, 'siteId' => $g->siteId, 'facilityUnitId' => $g->facilityUnitId,
            ])->values(),
            'assignments' => $assignments,
        ]);
    }

    private function currentSession(Request $request): AuthSession
    {
        return AuthSession::query()->findOrFail(RequestContext::sessionId());
    }
}
