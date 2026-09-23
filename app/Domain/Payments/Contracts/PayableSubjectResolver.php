<?php

namespace App\Domain\Payments\Contracts;

/**
 * Non-order things a customer can pay online through Paystack (bookings, memberships). The Booking / Membership modules
 * bind an implementation; the default one (NullPayableSubjectResolver) rejects with 422 `payable_subject_unsupported`.
 * On capture Payments dispatches {@see \App\Domain\Payments\Events\PaymentCaptured} (with subjectType/subjectId) and the
 * owning module confirms the booking / activates the membership from a listener.
 */
interface PayableSubjectResolver
{
    /**
     * @param  'BOOKING'|'MEMBERSHIP'  $type
     * @return null|array{amountDue: string, facilityId: string} null when unknown / not payable
     */
    public function resolve(string $type, string $id): ?array;
}
