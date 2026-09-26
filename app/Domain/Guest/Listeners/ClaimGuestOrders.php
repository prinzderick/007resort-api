<?php

namespace App\Domain\Guest\Listeners;

use App\Domain\Customer\Events\CustomerEmailVerified;
use App\Domain\Guest\Services\GuestOrderClaimer;

/** A customer's email became VERIFIED: attach earlier guest purchases made with that email (idempotent; the claimer re-checks verification in the DB). */
final class ClaimGuestOrders
{
    public function __construct(private readonly GuestOrderClaimer $claimer) {}

    public function handle(CustomerEmailVerified $e): void
    {
        $this->claimer->claim($e->customerId, $e->email);
    }
}
