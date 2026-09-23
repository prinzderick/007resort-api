<?php

namespace App\Domain\Sync\Support;

/**
 * What an applier decided about an inbound event. Appliers RETURN one of these (or throw: a throw is a
 * FAILED event, rolled back to a savepoint, stored and retried — never dropped).
 */
final class ApplyResult
{
    public const APPLIED = 'APPLIED';

    public const CONFLICT = 'CONFLICT';

    public const DEFERRED = 'DEFERRED';

    /** @param array<string, mixed>|null $localPayload */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $category = null,
        public readonly ?int $localVersion = null,
        public readonly ?int $incomingVersion = null,
        public readonly ?array $localPayload = null,
        public readonly ?string $detail = null,
    ) {}

    public static function applied(?string $detail = null): self
    {
        return new self(self::APPLIED, detail: $detail);
    }

    /**
     * The event was evaluated and deliberately NOT applied; a sync_conflict row is recorded.
     *
     * @param  'CONFIGURATION'|'PERMISSION'|'BOOKING'|'INVENTORY'|'ENTITY_VERSION'  $category
     * @param  array<string, mixed>|null  $localPayload  the receiver's current version of the data, for the reviewer
     */
    public static function conflict(string $category, ?int $localVersion = null, ?int $incomingVersion = null, ?array $localPayload = null, ?string $detail = null): self
    {
        return new self(self::CONFLICT, $category, $localVersion, $incomingVersion, $localPayload, $detail);
    }

    /** Not applicable yet (a prerequisite event has not arrived). Retried; escalates to ENTITY_VERSION_CONFLICT. */
    public static function deferred(string $reason): self
    {
        return new self(self::DEFERRED, detail: $reason);
    }
}
