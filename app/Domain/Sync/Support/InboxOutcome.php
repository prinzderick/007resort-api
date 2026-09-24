<?php

namespace App\Domain\Sync\Support;

/** Per-event result reported to the sender (contract SyncInboxResult.result). */
final class InboxOutcome
{
    public const APPLIED = 'APPLIED';

    public const DUPLICATE = 'DUPLICATE';

    public const CONFLICT = 'CONFLICT';

    public const DEFERRED = 'DEFERRED';

    public const FAILED = 'FAILED';

    public function __construct(public readonly string $eventId, public readonly string $result, public readonly ?string $detail = null) {}

    /** @return array{eventId: string, result: string, detail?: string} */
    public function toArray(): array
    {
        return array_filter(['eventId' => $this->eventId, 'result' => $this->result, 'detail' => $this->detail], fn ($v) => $v !== null);
    }
}
