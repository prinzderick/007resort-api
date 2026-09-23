<?php

namespace Tests\Support;

use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use Illuminate\Support\Facades\DB;

/** Writes a row and THEN blows up: proves the applier's writes are rolled back and the inbox row survives. */
class PoisonApplier implements SyncApplier
{
    public function apply(InboundEvent $event): ApplyResult
    {
        DB::table('sync_probe')->insert(['event_id' => $event->eventId, 'kind' => 'POISON-PARTIAL-WRITE', 'entity_id' => $event->entityId, 'version' => 0]);
        throw new \RuntimeException('boom: cannot apply this event');
    }
}
