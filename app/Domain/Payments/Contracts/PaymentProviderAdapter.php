<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Provider\VerifiedTransaction;
use App\Domain\Payments\Provider\WebhookEvent;
use App\Support\Http\ApiProblem;

/**
 * Provider adapter (ADR-0009). Paystack first; Flutterwave later = one more implementation of this interface.
 * Implementations must never log secrets and must treat every provider response as untrusted input.
 */
interface PaymentProviderAdapter
{
    /** Provider code stored on payment.provider, e.g. PAYSTACK. */
    public function code(): string;

    /**
     * Start a hosted payment.
     *
     * @param  array<string, mixed>  $metadata  echoed back by the provider (never put secrets here)
     * @return array{authorizationUrl: string, accessCode: ?string}
     *
     * @throws ApiProblem 502 provider_error when the provider is unreachable / rejects the request
     */
    public function initialize(string $reference, string $amount, string $currency, string $email, ?string $callbackUrl, array $metadata): array;

    /**
     * Ask the provider (server to server) what really happened to a transaction. This - not a webhook body, not a
     * client redirect - is the only thing allowed to make a payment CAPTURED.
     *
     * @throws ApiProblem 502 provider_error on transport errors / unparsable responses
     */
    public function verify(string $reference): VerifiedTransaction;

    /** Constant-time check of the webhook signature over the RAW request body. */
    public function verifySignature(string $rawBody, ?string $signature): bool;

    /** Parse a (signature-verified) webhook body. Returns null for malformed / unsupported bodies. */
    public function parseWebhook(string $rawBody): ?WebhookEvent;
}
