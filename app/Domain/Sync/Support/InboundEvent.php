<?php

namespace App\Domain\Sync\Support;

use App\Support\Ids;

/** A sync event as received from the peer (contract schema SyncEvent). Ids are canonical UUID strings. */
final class InboundEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly int $entityVersion,
        public readonly ?string $organizationId,
        public readonly ?string $siteId,
        public readonly ?string $facilityId,
        public readonly string $sourceNode,
        public readonly string $occurredAt,
        public readonly array $payload,
    ) {}

    /** @param array<string, mixed> $e camelCase envelope (already validated by the inbox request) */
    public static function fromEnvelope(array $e): self
    {
        $n = fn (?string $v) => $v === null || $v === '' ? null : Ids::normalize($v);

        return new self(
            Ids::normalize($e['eventId']),
            $e['eventType'],
            $e['entityType'],
            Ids::normalize($e['entityId']),
            (int) $e['entityVersion'],
            $n($e['organizationId'] ?? null),
            $n($e['siteId'] ?? null),
            $n($e['facilityId'] ?? null),
            $e['sourceNode'],
            $e['occurredAt'],
            (array) ($e['payload'] ?? []),
        );
    }

    /** Rebuild from a stored inbox_event row. */
    public static function fromRow(object $r): self
    {
        $u = fn (?string $b) => $b === null ? null : Ids::fromBinary($b);

        return new self(
            Ids::fromBinary($r->id),
            $r->event_type,
            (string) $r->entity_type,
            Ids::fromBinary($r->entity_id),
            (int) $r->entity_version,
            $u($r->organization_id),
            $u($r->site_id),
            $u($r->facility_id),
            $r->source_node,
            Ts::iso($r->occurred_at) ?? Ts::iso(Ts::db()),
            $r->payload === null ? [] : (array) json_decode($r->payload, true),
        );
    }
}
