<?php

namespace App\Domain\Membership\Listeners;

use App\Domain\Membership\Services\MembershipService;

/**
 * Wire-up stub for the Payments module. Registered (by class-name string) for
 * `App\Domain\Payments\Events\PaymentCaptured`. Until Payments lands nothing dispatches it.
 *
 * Expected event shape (any object; read defensively): `paymentId` (uuid), `reference` (?string),
 * and either `membershipId` or `metadata['membershipId']` (uuid) identifying what the payment was for.
 * Applying the same payment twice is a no-op (MembershipService::activateOnPayment is idempotent per paymentId).
 */
class ActivateMembershipOnPaymentCaptured
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function handle(object $event): void
    {
        $meta = (array) ($event->metadata ?? []);
        $membershipId = $event->membershipId ?? $meta['membershipId'] ?? null;
        $paymentId = $event->paymentId ?? null;
        if (! is_string($membershipId) || ! is_string($paymentId)) {
            return; // payment for something else (order, booking, ...)
        }
        $this->memberships->activateOnPayment($membershipId, $paymentId, isset($event->reference) ? (string) $event->reference : null);
    }
}
