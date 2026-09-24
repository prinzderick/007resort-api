<?php

namespace App\Domain\Membership\Listeners;

use App\Domain\Membership\Services\MembershipService;

/**
 * Subscribed (by class-name string, so it is inert until Payments lands) to `App\Domain\Payments\Events\PaymentCaptured`,
 * which carries `paymentId`, `subjectType` ('MEMBERSHIP'|'BOOKING'|null), `subjectId` and `provider`. Only MEMBERSHIP subjects are ours.
 * Payments dispatches it synchronously inside the capturing transaction, so activation commits atomically with the money.
 * Applying the same payment twice is a no-op (MembershipService::activateOnPayment is idempotent per paymentId).
 * A `membershipId` property / `metadata['membershipId']` is also honoured for other publishers.
 */
class ActivateMembershipOnPaymentCaptured
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function handle(object $event): void
    {
        $meta = (array) ($event->metadata ?? []);
        $membershipId = ($event->subjectType ?? null) === 'MEMBERSHIP' ? ($event->subjectId ?? null) : ($event->membershipId ?? $meta['membershipId'] ?? null);
        $paymentId = $event->paymentId ?? null;
        if (! is_string($membershipId) || ! is_string($paymentId)) {
            return; // payment for something else (order, booking, ...)
        }
        $this->memberships->activateOnPayment($membershipId, $paymentId, isset($event->provider) ? 'PAYMENT:'.$event->provider : null);
    }
}
