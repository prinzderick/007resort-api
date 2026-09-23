<?php

namespace App\Domain\Sync\Appliers;

use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use InvalidArgumentException;

/**
 * `ConfigurationUpdated` and `StaffRosterUpdated` (architecture/sync/event-catalogue.md): payload
 * `{ "domain": "<registered VersionedTargets key>", "changes": { ... } }`, entity = the changed row,
 * entityVersion = its row_version AFTER the change. The target's category (CONFIGURATION | PERMISSION) must match
 * the event type, so a config event can never write the staff roster or vice versa.
 */
final class VersionedDomainApplier implements SyncApplier
{
    public function __construct(private readonly VersionedTargets $targets, private readonly string $expectedCategory) {}

    public function apply(InboundEvent $event): ApplyResult
    {
        $domain = $event->payload['domain'] ?? null;
        $target = is_string($domain) ? $this->targets->get($domain) : null;
        if ($target === null) {
            throw new InvalidArgumentException('Unknown or missing "domain" for '.$event->eventType.': '.json_encode($domain));
        }
        if ($target->category !== $this->expectedCategory) {
            throw new InvalidArgumentException("Domain '{$domain}' is a {$target->category} target and cannot be changed by {$event->eventType}.");
        }

        return VersionedApply::apply($event, $target);
    }
}
