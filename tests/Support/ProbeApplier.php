<?php

namespace Tests\Support;

use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use Illuminate\Support\Facades\DB;

/** Test applier: records that (and in which order) an event was applied, in `sync_probe`. */
class ProbeApplier implements SyncApplier
{
    public function apply(InboundEvent $event): ApplyResult
    {
        DB::table('sync_probe')->insert(['event_id' => $event->eventId, 'kind' => $event->eventType, 'entity_id' => $event->entityId, 'version' => $event->entityVersion]);

        return ApplyResult::applied();
    }
}
