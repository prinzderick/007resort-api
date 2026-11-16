<?php

namespace App\Domain\Sync\Appliers;

use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use App\Support\Sync\RowSnapshot;

/**
 * Applies the rows an operational event carries (orders, payments, cash, stock, devices, memberships, customers, attendance).
 * The same class runs on both nodes. It writes straight to the tables, never through Outbox, so nothing echoes back.
 */
final class RowSnapshotApplier implements SyncApplier
{
    public function __construct(private readonly ?string $connection = null) {}

    public function apply(InboundEvent $event): ApplyResult
    {
        $snapshot = $event->payload['snapshot'] ?? null;
        if (! is_array($snapshot)) {
            // sent before snapshots existed: nothing to mirror, and it must not block the queue
            return ApplyResult::applied('legacy event without rows (skipped)');
        }
        $n = RowSnapshot::apply($snapshot, $this->connection);

        return ApplyResult::applied("{$n} row(s) mirrored");
    }
}
