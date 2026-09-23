<?php

namespace App\Domain\Sync\Services;

/**
 * Outbound channel to the peer node. Production = HttpPeerClient (mutually-authenticated HTTPS, outbound only).
 * Tests bind an in-process implementation that routes to a second app/database (see tests/Support/TwoNode).
 */
interface PeerClient
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     *
     * @throws PeerUnreachable when no HTTP response was obtained (DNS, refused, timeout, TLS)
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): PeerResponse;
}
