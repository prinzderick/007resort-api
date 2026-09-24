<?php

namespace App\Domain\Payments\Provider\Terminal;

/** Result of a terminal operation. `status` PENDING = a human/provider still has to confirm; CONFIRMED = provider-verified. */
final class TerminalCharge
{
    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const FAILED = 'FAILED';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $amount = null,
        public readonly ?string $message = null,
    ) {}
}
