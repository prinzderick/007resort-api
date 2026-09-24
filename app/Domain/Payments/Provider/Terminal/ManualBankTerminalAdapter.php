<?php

namespace App\Domain\Payments\Provider\Terminal;

use App\Domain\Payments\Contracts\PaymentTerminalAdapter;

/**
 * Default adapter: a normal bank POS machine (no API). The waiter keys in what the slip says (approval code / slip reference /
 * last 4); nothing here can confirm money, so every charge stays PENDING for the cashier. No callbacks, nothing to void remotely.
 */
final class ManualBankTerminalAdapter implements PaymentTerminalAdapter
{
    public const CODE = 'MANUAL_BANK';

    public function code(): string
    {
        return self::CODE;
    }

    public function initiateCharge(object $terminal, string $reference, string $amount, array $ctx): TerminalCharge
    {
        return new TerminalCharge(TerminalCharge::PENDING, $reference, null, $amount, 'Enter the amount on the bank machine; the cashier confirms against the slip.');
    }

    public function queryStatus(object $terminal, string $reference): TerminalCharge
    {
        return new TerminalCharge(TerminalCharge::PENDING, $reference);
    }

    public function parseCallback(string $rawBody, ?string $signature): ?TerminalCharge
    {
        return null;
    }

    public function void(object $terminal, string $reference): bool
    {
        return true; // nothing to void remotely: the slip is voided at the machine
    }

    public function refund(object $terminal, string $reference, string $amount): bool
    {
        return false; // refunds of manual bank payments are a manual treasury action (PAYMENTS.md: providerActionRequired)
    }
}
