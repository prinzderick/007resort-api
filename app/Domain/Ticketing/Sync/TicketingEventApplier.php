<?php

namespace App\Domain\Ticketing\Sync;

use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Ticketing\Services\EntitlementSyncApplier;

/**
 * Sync inbox applier for the ticketing events of the OTHER node (registered by TicketingServiceProvider when the Sync module exists):
 *   EntitlementIssued                                -> recreate the entitlement (same id + QR token) so the gate here can validate it;
 *   TicketRedeemed / RentalReleased / RentalReturned -> reporting mirror of what happened at the other node (exactly once per redemption id).
 * Redemption itself stays Local-authoritative (architecture/23): a mirrored redemption never blocks a local scan of the same item that
 * happens before the event arrives — that would be a Local-vs-Local race, guarded by the conditional UPDATE, not by sync.
 */
final class TicketingEventApplier implements SyncApplier
{
    public const EVENT_TYPES = ['EntitlementIssued', 'TicketRedeemed', 'RentalReleased', 'RentalReturned'];

    public function __construct(private readonly EntitlementSyncApplier $entitlements) {}

    public function apply(InboundEvent $e): ApplyResult
    {
        if ($e->eventType === 'EntitlementIssued') {
            return ApplyResult::applied($this->entitlements->apply($e->payload) ? null : 'entitlement already present');
        }
        $action = match ($e->eventType) {
            'TicketRedeemed' => (string) ($e->payload['action'] ?? 'ENTRY'),
            'RentalReleased' => 'RELEASE',
            'RentalReturned' => 'RETURN',
            default => throw new \InvalidArgumentException("Unsupported ticketing event {$e->eventType}"),
        };
        $mirrored = $this->entitlements->mirrorRedemption($action, $e->payload);

        return $mirrored === null ? ApplyResult::deferred('entitlement item has not arrived yet') : ApplyResult::applied($mirrored ? null : 'redemption already mirrored');
    }
}
