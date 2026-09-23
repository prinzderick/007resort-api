<?php

namespace App\Support\Sync;

use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Transactional outbox writer (ADR-0013, architecture/sync/outbox-inbox-design.md).
 * MUST be called inside the same DB transaction as the business change it describes, so the
 * change and the intent-to-sync commit or roll back together. The Sync module drains the table.
 *
 *   DB::transaction(function () use ($order) {
 *       $order->save();
 *       Outbox::record('OrderCreated', 'Order', $order->id, ['orderId' => $order->id, ...],
 *                      entityVersion: $order->row_version, facilityId: $order->facility_unit_id);
 *   });
 *
 * See docs/MODULES.md and architecture/sync/event-catalogue.md for event type names.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload  camelCase JSON-serialisable; money as decimal strings
     * @return string event id (UUID) — also the receiver's idempotency key
     */
    public static function record(
        string $eventType,
        string $entityType,
        string $entityId,
        array $payload,
        int $entityVersion = 1,
        ?string $organizationId = null,
        ?string $siteId = null,
        ?string $facilityId = null,
    ): string {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox::record must be called inside the business transaction (DB::transaction).');
        }
        $organizationId ??= Tenant::organizationId();
        $siteId ??= Tenant::siteId();
        if ($organizationId === null) {
            throw new LogicException('Outbox::record needs an organization id (none in context and none passed).');
        }

        $id = Ids::uuid7();
        DB::table('outbox_event')->insert([
            'id' => Ids::toBinary($id),
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => Ids::toBinary($entityId),
            'entity_version' => $entityVersion,
            'organization_id' => Ids::toBinary($organizationId),
            'site_id' => $siteId === null ? null : Ids::toBinary($siteId),
            'facility_id' => $facilityId === null ? null : Ids::toBinary($facilityId),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'sync_status' => 'LOCAL',
        ]);

        return $id;
    }
}
