<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\ConnectivityProbe;
use App\Support\Node;

/** Default probe until the Sync module binds a heartbeat-backed one: driven by config('booking.probe.*') / env. */
final class ConfigConnectivityProbe implements ConnectivityProbe
{
    public function cloudReachable(): bool
    {
        return Node::isCloud() || (bool) config('booking.probe.cloud_reachable', false);
    }

    public function localHeartbeatAgeSeconds(): ?int
    {
        return Node::isLocal() ? 0 : config('booking.probe.local_heartbeat_age_seconds');
    }
}
