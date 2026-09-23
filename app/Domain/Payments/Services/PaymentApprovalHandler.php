<?php

namespace App\Domain\Payments\Services;

use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The hook Orders' approval-decision service calls, inside the decision transaction, once a supervisor has APPROVED an
 * approval whose `action` is `payment.refund` or `payment.reversal` (see {@see \App\Domain\Payments\Contracts\ApprovalPort}).
 * A REJECTED / CANCELLED approval needs no Payments action: nothing was applied while it was PENDING.
 */
class PaymentApprovalHandler
{
    public function __construct(private readonly RefundService $refunds) {}

    public function handles(string $action): bool
    {
        return in_array($action, [RefundService::REFUND, RefundService::REVERSAL], true);
    }

    /** @return array<string, mixed> the created Refund / PaymentReversal */
    public function apply(string $approvalId, string $deciderStaffId): array
    {
        $a = DB::table('approval')->where('id', Ids::toBinary($approvalId))->first();
        if ($a === null || ! $this->handles((string) $a->action)) {
            throw ApiProblem::notFound('not_found', 'No payment approval with that id.');
        }
        if ($a->status !== 'APPROVED') {
            throw ApiProblem::conflict('payment_state_invalid', 'The approval has not been approved.');
        }
        $payload = json_decode((string) $a->payload, true, 512, JSON_THROW_ON_ERROR);

        return $this->refunds->applyApproved(
            Ids::fromBinary($a->entity_id), $payload, Ids::fromBinary($a->requested_by), $deciderStaffId, $approvalId,
        );
    }
}
