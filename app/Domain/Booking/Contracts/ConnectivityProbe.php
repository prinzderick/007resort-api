<?php

namespace App\Domain\Booking\Contracts;

/**
 * Node-connectivity facts the Booking Authority policy needs. IMPLEMENTED BY THE SYNC MODULE (heartbeat, ADR-0013);
 * the default binding (ConfigConnectivityProbe) reads config/env so the policy is usable and testable today.
 */
interface ConnectivityProbe
{
    /** Local node: can this node currently reach the Cloud API? (Cloud node: always true.) */
    public function cloudReachable(): bool;

    /** Cloud node: seconds since the Local node's last heartbeat, or null when never seen. (Local node: 0.) */
    public function localHeartbeatAgeSeconds(): ?int;
}
