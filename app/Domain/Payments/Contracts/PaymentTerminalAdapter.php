<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Provider\Terminal\TerminalCharge;
use App\Support\Http\ApiProblem;

/**
 * Card-terminal adapter (docs/WAITER_COLLECTION.md section 5). Resolved per `payment_terminal.provider` by
 * {@see \App\Domain\Payments\Services\TerminalAdapterRegistry}. Today's default is {@see \App\Domain\Payments\Provider\Terminal\ManualBankTerminalAdapter}
 * (a normal bank POS machine with no API: everything stays PENDING and the cashier confirms against the slip). A real
 * integration implements this interface and is registered under a provider code; endpoints do not change.
 *
 * Implementations treat every provider response as untrusted and never log secrets. Only a `CONFIRMED` result from a
 * SERVER-VERIFIED source (the adapter's own status query / signature-checked callback) may make a payment CAPTURED.
 */
interface PaymentTerminalAdapter
{
    /** Provider code stored on payment_terminal.provider. */
    public function code(): string;

    /**
     * Push a charge to the terminal.
     *
     * @param  object  $terminal  payment_terminal row
     * @param  array<string, mixed>  $ctx  orderNumber, facilityId, staffId
     *
     * @throws ApiProblem 501 terminal_provider_unavailable / 502 provider_error
     */
    public function initiateCharge(object $terminal, string $reference, string $amount, array $ctx): TerminalCharge;

    /** Ask the provider what happened to a charge. */
    public function queryStatus(object $terminal, string $reference): TerminalCharge;

    /** Parse a signature-verified provider callback; null when unsupported / unverifiable / malformed. */
    public function parseCallback(string $rawBody, ?string $signature): ?TerminalCharge;

    /** Void an unsettled charge on the terminal. */
    public function void(object $terminal, string $reference): bool;

    /** Refund a settled charge through the terminal provider. */
    public function refund(object $terminal, string $reference, string $amount): bool;
}
