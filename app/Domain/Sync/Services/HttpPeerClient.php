<?php

namespace App\Domain\Sync\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Real HTTPS transport: `PEER_URL` + `/api/v1`, bearer `PEER_NODE_TOKEN`. The token is never logged. */
final class HttpPeerClient implements PeerClient
{
    public function request(string $method, string $path, array $query = [], ?array $json = null): PeerResponse
    {
        $base = (string) config('sync.peer_url');
        $token = (string) config('sync.peer_node_token');
        if ($base === '' || $token === '') {
            throw new PeerUnreachable('PEER_URL / PEER_NODE_TOKEN are not configured.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->asJson()
                ->timeout((int) config('sync.http_timeout'))->connectTimeout(min(5, (int) config('sync.http_timeout')))
                ->baseUrl(rtrim($base, '/').'/api/v1')
                ->send(strtoupper($method), ltrim($path, '/'), array_filter(['query' => $query ?: null, 'json' => $json], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            throw new PeerUnreachable('peer unreachable: '.class_basename($e).' - '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        $retry = $response->header('Retry-After');

        return new PeerResponse($response->status(), (array) ($response->json() ?? []), is_numeric($retry) ? (int) $retry : null);
    }
}
