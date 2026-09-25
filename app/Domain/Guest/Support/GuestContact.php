<?php

namespace App\Domain\Guest\Support;

/** Validated + normalised guest input. */
final class GuestContact
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly bool $marketingConsent,
        public readonly string $consentVersion,
        public readonly ?string $clientIp = null,
    ) {}

    /** @return array{name: string, email: string, phone: string} */
    public function snapshot(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone];
    }
}
