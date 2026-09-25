<?php

namespace App\Domain\Customer\Events;

/**
 * Fired (after the transaction commits) whenever a customer's login email BECOMES verified: password signup verification,
 * social sign-in/link with a provider-verified email, link confirmation, adding an email by code.
 * Listeners (e.g. guest-checkout order claiming) must be idempotent. `source`: password|social|social_link_confirm|email_change.
 * The same fact is written to the outbox as `CustomerEmailVerified` {customerId, email, source} in the business transaction.
 */
final class CustomerEmailVerified
{
    public function __construct(public readonly string $customerId, public readonly string $email, public readonly string $source) {}
}
