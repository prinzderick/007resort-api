<?php

namespace App\Domain\Payments\Provider\Terminal;

use App\Domain\Payments\Contracts\PaymentTerminalAdapter;
use App\Support\Http\ApiProblem;

/**
 * Placeholder for a future Paystack Terminal integration (drop-in point: implement the five methods, keep the class name/code).
 * It is registered but DISABLED (`payments.terminals.paystack_enabled` / PAYMENTS_TERMINAL_PAYSTACK_ENABLED=false): every call is
 * a clear 501, and nothing can be confirmed through it.
 */
final class PaystackTerminalAdapter implements PaymentTerminalAdapter
{
    public const CODE = 'PAYSTACK_TERMINAL';

    public function code(): string
    {
        return self::CODE;
    }

    public function initiateCharge(object $terminal, string $reference, string $amount, array $ctx): TerminalCharge
    {
        throw $this->unavailable();
    }

    public function queryStatus(object $terminal, string $reference): TerminalCharge
    {
        throw $this->unavailable();
    }

    public function parseCallback(string $rawBody, ?string $signature): ?TerminalCharge
    {
        return null;
    }

    public function void(object $terminal, string $reference): bool
    {
        throw $this->unavailable();
    }

    public function refund(object $terminal, string $reference, string $amount): bool
    {
        throw $this->unavailable();
    }

    private function unavailable(): ApiProblem
    {
        return new ApiProblem(501, 'terminal_provider_unavailable', 'The Paystack terminal integration is not enabled on this node.', 'Not implemented');
    }
}
