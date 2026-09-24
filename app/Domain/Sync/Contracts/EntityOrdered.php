<?php

namespace App\Domain\Sync\Contracts;

/**
 * Optional marker for appliers whose events for one entity MUST be applied in entity_version order
 * (e.g. OrderCreated -> OrderCancelled). The inbox tracks the highest applied version per entity
 * (sync_entity_version) and DEFERS an event whose version leaves a gap, applying it automatically as soon as the
 * missing predecessor lands. A gap that outlives `sync.defer_escalate_after` escalates to ENTITY_VERSION_CONFLICT.
 * An event older than what is already applied is an ENTITY_VERSION_CONFLICT too (never applied).
 *
 * Appliers of two-way-editable entities (configuration, staff roster) should instead use VersionedApply, which
 * compares against the domain row's own row_version.
 */
interface EntityOrdered
{
    /** The entity_version of the very first event of an entity (normally 1). */
    public function firstEntityVersion(): int;
}
