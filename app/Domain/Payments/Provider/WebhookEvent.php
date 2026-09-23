<?php

namespace App\Domain\Payments\Provider;

final class WebhookEvent
{
    public function __construct(
        /** Provider-unique id used for duplicate protection: UNIQUE (provider, provider_event_id). */
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $reference,
        /** @var array<string, mixed> */
        public readonly array $payload,
    ) {}
}
