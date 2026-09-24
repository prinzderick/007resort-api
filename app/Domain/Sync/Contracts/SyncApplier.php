<?php

namespace App\Domain\Sync\Contracts;

use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;

/**
 * Applies ONE inbound sync event to the receiving node's domain tables.
 *
 * Runs inside the inbox transaction (a savepoint under the inbox_event row): write domain rows, do not commit,
 * do not start a competing top-level transaction, do not call the network. Must be idempotent by nature of the
 * inbox (the same event_id is never applied twice), but should be defensive about re-application anyway.
 *
 * Return ApplyResult::applied() / ::conflict() / ::deferred(). Throwing marks the event FAILED (retried later).
 * Register from your module's service provider — see docs/sync-engine.md ("Registering an applier").
 */
interface SyncApplier
{
    public function apply(InboundEvent $event): ApplyResult;
}
