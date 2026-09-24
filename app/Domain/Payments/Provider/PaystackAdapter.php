<?php

namespace App\Domain\Payments\Provider;

use App\Domain\Payments\Contracts\PaymentProviderAdapter;
use App\Support\Http\ApiProblem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Paystack adapter (ADR-0009). Docs: https://paystack.com/docs/api/transaction/ and /payments/webhooks/.
 *
 *  - Amounts are KOBO integers on the wire; we convert with bcmath from/to NGN decimal strings (no floats).
 *  - The webhook signature is HMAC-SHA512 of the raw body keyed with the account secret key (`x-paystack-signature`).
 *  - Nothing here logs or returns the secret key, the signature, or provider response bodies.
 */
class PaystackAdapter implements PaymentProviderAdapter
{
    public function code(): string
    {
        return 'PAYSTACK';
    }

    public function initialize(string $reference, string $amount, string $currency, string $email, ?string $callbackUrl, array $metadata): array
    {
        $kobo = self::toKobo($amount);
        $payload = array_filter([
            'reference' => $reference,
            'amount' => $kobo,
            'currency' => $currency,
            'email' => $email,
            'callback_url' => $callbackUrl ?: config('payments.paystack.callback_url'),
            'metadata' => $metadata === [] ? null : $metadata,
        ], fn ($v) => $v !== null && $v !== '');

        $body = $this->call(fn (PendingRequest $h) => $h->asJson()->post('/transaction/initialize', $payload), 'initialize');
        $url = $body['data']['authorization_url'] ?? null;
        if (($body['status'] ?? false) !== true || ! is_string($url) || $url === '') {
            throw new ApiProblem(502, 'provider_error', 'Paystack did not return an authorization URL.', 'Bad gateway');
        }

        return ['authorizationUrl' => $url, 'accessCode' => $body['data']['access_code'] ?? null];
    }

    /** Paystack "Pay with Transfer": POST /charge with `bank_transfer` returns a dynamic virtual account for the reference. */
    public function createTransferAccount(string $reference, string $amount, string $currency, string $email, array $metadata): array
    {
        $ttl = (int) config('payments.collection.transfer_account_ttl_minutes', 60);
        $payload = array_filter([
            'reference' => $reference,
            'amount' => self::toKobo($amount),
            'currency' => $currency,
            'email' => $email,
            'bank_transfer' => ['account_expires_at' => now('UTC')->addMinutes($ttl)->format('Y-m-d\TH:i:s\Z')],
            'metadata' => $metadata === [] ? null : $metadata,
        ], fn ($v) => $v !== null && $v !== '');
        $body = $this->call(fn (PendingRequest $h) => $h->asJson()->post('/charge', $payload), 'transfer account');
        $d = is_array($body['data'] ?? null) ? $body['data'] : [];
        $d = $d + (is_array($d['authorization'] ?? null) ? $d['authorization'] : []);
        $number = $d['account_number'] ?? null;
        $bank = is_array($d['bank'] ?? null) ? ($d['bank']['name'] ?? null) : ($d['bank'] ?? $d['bank_name'] ?? null);
        if (($body['status'] ?? false) !== true || ! is_string($number) || $number === '' || ! is_string($bank)) {
            throw new ApiProblem(502, 'provider_error', 'Paystack did not return a transfer account.', 'Bad gateway');
        }

        return [
            'bankName' => $bank, 'accountNumber' => $number, 'accountName' => (string) ($d['account_name'] ?? '007 Resort & Spa'),
            'expiresAt' => isset($d['account_expires_at']) && is_string($d['account_expires_at']) ? $d['account_expires_at'] : null,
        ];
    }

    public function verify(string $reference): VerifiedTransaction
    {
        $response = null;
        $body = $this->call(function (PendingRequest $h) use ($reference, &$response) {
            $response = $h->get('/transaction/verify/'.rawurlencode($reference));

            return $response;
        }, 'verify', allow404: true);

        if ($body === null) { // Paystack has never heard of this reference
            return new VerifiedTransaction(VerifiedTransaction::PENDING, $reference, '0.0000', 'NGN', null, 'not_found');
        }
        $data = $body['data'] ?? null;
        if (($body['status'] ?? false) !== true || ! is_array($data)) {
            throw new ApiProblem(502, 'provider_error', 'Paystack returned an unexpected verification response.', 'Bad gateway');
        }
        // Defence in depth: the provider must be talking about the reference we asked for.
        if (($data['reference'] ?? null) !== $reference) {
            throw new ApiProblem(502, 'provider_error', 'Paystack verification reference mismatch.', 'Bad gateway');
        }
        $status = match (strtolower((string) ($data['status'] ?? ''))) {
            'success' => VerifiedTransaction::SUCCESS,
            'failed', 'reversed' => VerifiedTransaction::FAILED,
            default => VerifiedTransaction::PENDING,   // abandoned / ongoing / pending / queued
        };
        $kobo = $data['amount'] ?? null;
        if (! is_int($kobo) && ! (is_string($kobo) && ctype_digit($kobo))) {
            throw new ApiProblem(502, 'provider_error', 'Paystack returned a non-integer amount.', 'Bad gateway');
        }

        return new VerifiedTransaction(
            $status,
            $reference,
            bcdiv((string) $kobo, '100', 4),
            strtoupper((string) ($data['currency'] ?? 'NGN')),
            isset($data['id']) ? (string) $data['id'] : null,
            isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
        );
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('payments.paystack.secret_key');
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower(trim($signature)));
    }

    public function parseWebhook(string $rawBody): ?WebhookEvent
    {
        $json = json_decode($rawBody, true);
        if (! is_array($json) || ! is_string($json['event'] ?? null) || ! is_array($json['data'] ?? null)) {
            return null;
        }
        $data = $json['data'];
        $reference = isset($data['reference']) && is_string($data['reference']) && $data['reference'] !== '' ? $data['reference'] : null;
        $key = $data['id'] ?? $reference;
        // Paystack sends no unique delivery id; (event, transaction id) is unique per real event and identical across redeliveries.
        $eventId = $key !== null ? $json['event'].':'.$key : $json['event'].':sha256:'.hash('sha256', $rawBody);

        return new WebhookEvent(substr($eventId, 0, 128), $json['event'], $reference, $json);
    }

    /** NGN decimal string -> kobo integer string; throws if the amount is not a whole number of kobo. */
    public static function toKobo(string $amount): string
    {
        $kobo = bcmul($amount, '100', 4);
        if (bccomp($kobo, bcadd($kobo, '0', 0), 4) !== 0) {
            throw ApiProblem::unprocessable('validation_failed', 'Online payments must be a whole number of kobo (max 2 decimal places).');
        }

        return bcadd($kobo, '0', 0);
    }

    /**
     * @param  callable(PendingRequest): Response  $do
     * @return array<string, mixed>|null
     */
    private function call(callable $do, string $op, bool $allow404 = false): ?array
    {
        $secret = (string) config('payments.paystack.secret_key');
        if ($secret === '') {
            throw new ApiProblem(502, 'provider_error', 'Paystack is not configured on this node.', 'Bad gateway');
        }
        try {
            $http = Http::baseUrl(rtrim((string) config('payments.paystack.base_url'), '/'))
                ->withToken($secret)->acceptJson()->timeout((int) config('payments.paystack.timeout_seconds'));
            $response = $do($http);
        } catch (Throwable) {
            throw new ApiProblem(502, 'provider_error', "Paystack {$op} failed (network).", 'Bad gateway');
        }
        if ($allow404 && $response->status() === 404) {
            return null;
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new ApiProblem(502, 'provider_error', "Paystack {$op} failed (HTTP {$response->status()}).", 'Bad gateway');
        }

        return $json;
    }
}
