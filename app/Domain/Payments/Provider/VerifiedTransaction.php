<?php

namespace App\Domain\Payments\Provider;

/** Provider-confirmed facts about a transaction. `status`: SUCCESS | FAILED | PENDING (abandoned / ongoing / unknown). */
final class VerifiedTransaction
{
    public const SUCCESS = 'SUCCESS';

    public const FAILED = 'FAILED';

    public const PENDING = 'PENDING';

    public function __construct(
        public readonly string $status,
        public readonly string $reference,
        /** Decimal NGN string (kobo converted with bcmath). */
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $providerTransactionId,
        public readonly ?string $gatewayResponse = null,
    ) {}
}
