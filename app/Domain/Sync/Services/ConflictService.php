<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Sync\Support\Ts;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Writes the sync_conflict register (architecture/sync/conflict-resolution-matrix.md). Never overwrites data. */
final class ConflictService
{
    /** Record (or reopen) the conflict for an event. Call inside the inbox transaction. */
    public function record(InboundEvent $event, ApplyResult $result): string
    {
        $now = Ts::db();
        $existing = DB::table('sync_conflict')->where('event_id', Ids::toBinary($event->eventId))->first(['id']);
        $id = $existing ? Ids::fromBinary($existing->id) : Ids::uuid7();
        $row = [
            'category' => $result->category,
            'status' => 'OPEN',
            'event_type' => $event->eventType,
            'entity_type' => $event->entityType,
            'entity_id' => Ids::toBinary($event->entityId),
            'source_node' => $event->sourceNode,
            'local_version' => $result->localVersion,
            'incoming_version' => $result->incomingVersion ?? $event->entityVersion,
            'local_payload' => $result->localPayload === null ? null : json_encode($result->localPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'incoming_payload' => json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'detail' => $result->detail === null ? null : mb_substr($result->detail, 0, 500),
            'organization_id' => $event->organizationId ? Ids::toBinary($event->organizationId) : null,
            'site_id' => $event->siteId ? Ids::toBinary($event->siteId) : null,
            'resolved_at' => null,
            'resolved_by_staff_id' => null,
            'resolution' => null,
            'resolution_note' => null,
        ];
        if ($existing) {
            DB::table('sync_conflict')->where('id', $existing->id)->update($row);
        } else {
            DB::table('sync_conflict')->insert($row + [
                'id' => Ids::toBinary($id),
                'event_id' => Ids::toBinary($event->eventId),
                'detected_at' => $now,
            ]);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public static function present(object $r): array
    {
        $json = fn (?string $s) => $s === null ? null : json_decode($s, true);
        $u = fn (?string $b) => $b === null ? null : Ids::fromBinary($b);

        return [
            'id' => Ids::fromBinary($r->id),
            'category' => $r->category,
            'status' => $r->status,
            'eventId' => $u($r->event_id),
            'eventType' => $r->event_type,
            'entityType' => $r->entity_type,
            'entityId' => Ids::fromBinary($r->entity_id),
            'sourceNode' => $r->source_node,
            'localVersion' => $r->local_version === null ? null : (int) $r->local_version,
            'incomingVersion' => $r->incoming_version === null ? null : (int) $r->incoming_version,
            'localPayload' => $json($r->local_payload),
            'incomingPayload' => $json($r->incoming_payload),
            'detail' => $r->detail,
            'detectedAt' => Ts::iso($r->detected_at),
            'resolvedAt' => Ts::iso($r->resolved_at),
            'resolvedByStaffId' => $u($r->resolved_by_staff_id),
            'resolution' => $r->resolution,
            'resolutionNote' => $r->resolution_note,
        ];
    }
}
