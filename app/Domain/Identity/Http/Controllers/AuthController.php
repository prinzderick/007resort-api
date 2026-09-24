<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\AuthSession;
use App\Domain\Identity\Models\Credential;
use App\Domain\Identity\Models\UserAccount;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Identity\Services\StaffAuthService;
use App\Domain\Identity\Services\StepUpService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AuthController
{
    public function __construct(
        private readonly StaffAuthService $auth,
        private readonly StepUpService $stepUp,
        private readonly PermissionChecker $permissions,
    ) {}

    /**
     * Contract body `{credentialType, identifier, secret}`. The pre-contract shape `{username, password|pin}` is still accepted.
     * NFC_CARD: identifier = card uid, secret = the staff PIN.
     */
    public function login(Request $request): JsonResponse
    {
        [$type, $identifier, $secret] = $this->credentialsFrom($request);

        return response()->json($this->auth->login($type, $identifier, $secret, $request->ip(), $request->userAgent(), RequestContext::deviceId()));
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refreshToken' => ['required', 'string', 'max:255']]);

        return response()->json($this->auth->refresh($data['refreshToken'], $request->ip(), $request->userAgent()));
    }

    public function logout(Request $request): Response
    {
        $data = $request->validate(['allSessions' => ['nullable', 'boolean']]);
        $this->auth->logout($this->currentSession(), (bool) ($data['allSessions'] ?? false));

        return response()->noContent();
    }

    /** Supervisor step-up: verifies the APPROVER's credential + permission, returns a single-use X-Step-Up-Token. */
    public function stepUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credentialType' => ['required', 'in:PASSWORD,PIN,NFC_CARD'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'secret' => ['required', 'string', 'max:255'],
            'permission' => ['required', 'string', 'max:96'],
            'entityType' => ['nullable', 'string', 'max:64'],
            'entityId' => ['nullable', 'uuid'],
        ]);
        if (! DB::table('permission')->where('code', $data['permission'])->exists()) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown permission.', ['permission' => ['Unknown permission code.']]);
        }

        return response()->json($this->stepUp->issue(
            $data['credentialType'], (string) ($data['identifier'] ?? ''), $data['secret'], $data['permission'],
            $data['entityType'] ?? null, $data['entityId'] ?? null, $request->ip(),
        ));
    }

    /** Own session: always allowed. Any other session: requires `session.revoke`. */
    public function revoke(Request $request, string $id): Response
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Session was not found.');
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $id = Ids::normalize($id);

        if ($id !== RequestContext::sessionId() && ! $this->permissions->can(RequestContext::staffId(), 'session.revoke')) {
            throw ApiProblem::permissionDenied('session.revoke');
        }
        $this->auth->revokeSession($id, $data['reason'] ?? null);

        return response()->noContent();
    }

    /** GET /auth/me (and alias /me): contract `{staff, session, device}` plus `username`, `grants`, `assignments`. */
    public function me(Request $request): JsonResponse
    {
        /** @var UserAccount $account */
        $account = $request->user();
        $staff = $account->staff;
        $session = $this->currentSession();
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
            'staff' => $this->auth->staffPayload($staff) + ['email' => $staff->email, 'phone' => $staff->phone, 'organizationId' => $staff->organization_id, 'siteId' => $staff->site_id],
            'session' => ['id' => $session->id, 'expiresAt' => $session->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'), 'deviceId' => $session->device_id],
            'device' => RequestContext::deviceId() ? ['id' => RequestContext::deviceId()] : null,
            'username' => $account->username,
            'grants' => $grants->map(fn ($g) => [
                'permission' => $g->permission, 'requiresApproval' => $g->requiresApproval, 'scopeLevel' => $g->scopeLevel,
                'organizationId' => $g->organizationId, 'siteId' => $g->siteId, 'facilityUnitId' => $g->facilityUnitId,
            ])->values(),
            'assignments' => $assignments,
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} type, identifier, secret */
    private function credentialsFrom(Request $request): array
    {
        if ($request->has('credentialType')) {
            $d = $request->validate([
                'credentialType' => ['required', 'in:PASSWORD,PIN,NFC_CARD'],
                'identifier' => ['required', 'string', 'max:100'],
                'secret' => ['required', 'string', 'max:255'],
            ]);

            return [$d['credentialType'], $d['identifier'], $d['secret']];
        }
        // legacy shape
        $d = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255', 'required_without:pin'],
            'pin' => ['nullable', 'string', 'max:32', 'required_without:password'],
        ]);

        return ! empty($d['password'])
            ? [Credential::PASSWORD, $d['username'], $d['password']]
            : [Credential::PIN, $d['username'], $d['pin']];
    }

    private function currentSession(): AuthSession
    {
        return AuthSession::query()->findOrFail(RequestContext::sessionId());
    }
}
