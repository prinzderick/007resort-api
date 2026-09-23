<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\ConnectivityProbe;
use App\Domain\Sync\Services\SiteAvailability;
use App\Domain\Sync\Services\SyncState;
use App\Support\Node;

/** Heartbeat-backed probe (Sync module): Local asks "did a round trip to Cloud succeed recently?", Cloud asks "how old is the site's last heartbeat?". */
final class SyncConnectivityProbe implements ConnectivityProbe
{
    public function cloudReachable(): bool
    {
        return Node::isCloud() || SyncState::peerReachable();
    }

    public function localHeartbeatAgeSeconds(): ?int
    {
        if (Node::isLocal()) {
            return 0;
        }

        return app(SiteAvailability::class)->snapshot()['ageSeconds'];
    }
}
