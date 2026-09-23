<?php

namespace App\Domain\Sync\Services;

use App\Support\Ids;

/**
 * Peer credentials (architecture/17 §7.1). We store only SHA-256 hashes of long, random, machine-generated
 * tokens (never the token) in NODE_TOKEN_HASHES = "local:<hex>,local@<siteUuid>:<hex>,...". A token is 256 bits of
 * entropy, so a fast hash is the right tool (a slow password hash would only add latency to every sync call).
 * Rotation = add the new hash, roll the peer's PEER_NODE_TOKEN, remove the old hash. No deployment, no downtime.
 */
final class NodeCredentials
{
    public const PREFIX = 'r007n_';

    /** @return array{node: string, siteId: ?string, credentialId: string}|null */
    public static function authenticate(?string $token): ?array
    {
        if ($token === null || strlen($token) < 32 || strlen($token) > 256) {
            return null;
        }
        $hash = hash('sha256', $token);
        $match = null;
        foreach (self::entries() as $e) {
            // no early break: constant work regardless of which entry matches
            if (hash_equals($e['hash'], $hash) && $match === null) {
                $match = ['node' => $e['node'], 'siteId' => $e['siteId'], 'credentialId' => substr($hash, 0, 12)];
            }
        }

        return $match;
    }

    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    /** @return list<array{node: string, siteId: ?string, hash: string}> */
    public static function entries(): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('sync.node_token_hashes')))) as $entry) {
            if (! preg_match('/^(local|cloud)(?:@([0-9a-fA-F-]{32,36}))?:([0-9a-f]{64})$/i', $entry, $m)) {
                continue; // malformed entry: ignored (never matches)
            }
            $out[] = ['node' => strtolower($m[1]), 'siteId' => $m[2] !== '' ? Ids::normalize($m[2]) : null, 'hash' => strtolower($m[3])];
        }

        return $out;
    }
}
