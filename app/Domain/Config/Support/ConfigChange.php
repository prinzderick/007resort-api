<?php

namespace App\Domain\Config\Support;

use App\Support\Audit\Audit;
use App\Support\Sync\Outbox;

/**
 * One audited, outboxed configuration change (call INSIDE the business transaction).
 *
 * Audit: `config.<what>` rows with old/new (money as strings) so every config screen can show a change history (GET /audit).
 * Outbox: `ConfigurationUpdated {domain, changes}` with entityVersion = the aggregate's row_version AFTER the change, so the
 * receiving node applies it version-checked (architecture/sync/conflict-resolution-matrix.md: stale => CONFIGURATION_CONFLICT).
 */
final class ConfigChange
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $changes  payload `changes` (what the receiver applies)
     */
    public static function record(
        string $auditAction,
        string $entityType,
        string $entityId,
        ?array $old,
        ?array $new,
        string $domain,
        array $changes,
        int $version,
        ?string $facilityId = null,
        ?string $organizationId = null,
        ?string $siteId = null,
        ?string $outboxEntityType = null,
        ?string $outboxEntityId = null,
        string $eventType = 'ConfigurationUpdated',
    ): void {
        Audit::record($auditAction, $entityType, $entityId, $old, $new, organizationId: $organizationId, siteId: $siteId, facilityUnitId: $facilityId);
        Outbox::record($eventType, $outboxEntityType ?? $entityType, $outboxEntityId ?? $entityId, ['domain' => $domain, 'changes' => $changes], $version, organizationId: $organizationId, siteId: $siteId, facilityId: $facilityId);
    }
}
