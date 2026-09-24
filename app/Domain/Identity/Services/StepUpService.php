<?php

namespace App\Domain\Identity\Services;

use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Supervisor step-up (contract `POST /auth/staff/step-up` + `X-Step-Up-Token`). A supervisor authenticates on the
 * operator's device; we issue a single-use token (5 min, kept in Redis — losing it merely forces a re-approval, it is
 * never authoritative data; the approval itself is audited in the hash chain).
 *
 * Consuming (domain code, e.g. void):
 *   $approval = app(StepUpService::class)->consume($request, 'order.void.approve', 'Order', $order->id);  // null if no header
 *   or route middleware `stepup:order.void.approve` -> RequestContext::approverId().
 */
class StepUpService
{
    public const TTL_SECONDS = 300;

    public function __construct(private readonly StaffAuthService $auth) {}

    /** @return array<string, mixed> StepUpResult */
    public function issue(string $type, string $identifier, string $secret, string $permission, ?string $entityType, ?string $entityId, ?string $ip): array
    {
        [, $approver] = $this->auth->verifyApprover($type, $identifier, $secret, $permission, $ip);

        $token = 'r7s_'.rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Cache::put('stepup:'.hash('sha256', $token), [
            'approverStaffId' => $approver->id,
            'requestedByStaffId' => RequestContext::staffId(),
            'permission' => $permission,
            'entityType' => $entityType,
            'entityId' => $entityId ? Ids::normalize($entityId) : null,
        ], self::TTL_SECONDS);

        Audit::record('staff.step_up', 'Staff', $approver->id, new: [
            'permission' => $permission, 'approverStaffId' => $approver->id, 'requestedByStaffId' => RequestContext::staffId(),
            'entityType' => $entityType, 'entityId' => $entityId,
        ]);

        return [
            'stepUpToken' => $token,
            'expiresInSeconds' => self::TTL_SECONDS,
            'approver' => ['id' => $approver->id, 'displayName' => $approver->displayName()],
        ];
    }

    /**
     * Validate AND burn the token from `X-Step-Up-Token`. Returns the approver staff id, or null when no header is sent.
     *
     * @throws ApiProblem 403 step_up_required when the token is unknown/expired/used or doesn't cover this action
     */
    public function consume(Request $request, string $permission, ?string $entityType = null, ?string $entityId = null): ?string
    {
        $token = $request->header('X-Step-Up-Token');
        if ($token === null || $token === '') {
            return null;
        }
        $data = Cache::pull('stepup:'.hash('sha256', $token));
        if (! is_array($data)
            || $data['permission'] !== $permission
            || ($data['requestedByStaffId'] !== null && $data['requestedByStaffId'] !== RequestContext::staffId())
            || ($data['entityType'] !== null && $entityType !== null && $data['entityType'] !== $entityType)
            || ($data['entityId'] !== null && $entityId !== null && $data['entityId'] !== Ids::normalize($entityId))) {
            throw ApiProblem::forbidden('step_up_required', 'The step-up token is invalid, expired or does not cover this action.');
        }

        return $data['approverStaffId'];
    }
}
