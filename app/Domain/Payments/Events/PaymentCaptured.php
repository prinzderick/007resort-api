<?php

namespace App\Domain\Payments\Events;

/**
 * Fired (synchronously, inside the capturing DB transaction) whenever money is captured. Other modules subscribe:
 * Booking confirms a paid booking, Membership activates a paid membership, Reporting refreshes totals...
 * Orders is NOT dependent on this event - Payments calls Orders through OrderPort so settlement is atomic.
 */
final class PaymentCaptured
{
    /**
     * @param  list<string>  $orderIds
     * @param  list<array{paymentId: string, tenderType: string, amount: string}>  $tenders
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $groupId,
        public readonly array $orderIds,
        public readonly string $amount,
        public readonly array $tenders,
        public readonly string $facilityId,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly string $provider = 'MANUAL',
    ) {}
}
