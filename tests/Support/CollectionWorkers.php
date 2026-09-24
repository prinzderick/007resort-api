<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Worker bodies for the waiter-collection concurrency tests (each runs in its own PHP process / MySQL connection). */
final class CollectionWorkers
{
    /**
     * Worker i acts as actors[i % count]: [bearer token, device token|null]. Each worker uses its own Idempotency-Key.
     *
     * @param  list<array{0: string, 1: ?string}>  $actors
     * @param  ?array<string, mixed>  $body
     * @return array{0: int, 1: mixed}
     */
    public function call(int $index, array $actors, string $method, string $uri, string $keyPrefix, ?array $body): array
    {
        [$token, $device] = $actors[$index % count($actors)];
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => $keyPrefix.'-'.$index];
        if ($device !== null) {
            $server['HTTP_X_DEVICE_TOKEN'] = $device;
        }
        $res = app(Kernel::class)->handle(Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body)));

        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    }

    /** Worker i posts `$body` to `$uris[i]` as the same actor (one distinct order per worker, one waiter). */
    public function callEach(int $index, string $token, string $deviceToken, array $uris, array $body): array
    {
        return $this->call($index, [[$token, $deviceToken]], 'POST', $uris[$index], 'each', $body);
    }

    /** Even workers confirm, odd workers reject the same payment (confirm-vs-reject race). Actors: [cashier, supervisor]. */
    public function confirmOrReject(int $index, array $actors, string $paymentId): array
    {
        return $index % 2 === 0
            ? $this->call($index, $actors, 'POST', "/api/v1/payments/{$paymentId}/confirm", 'cr', [])
            : $this->call($index, $actors, 'POST', "/api/v1/payments/{$paymentId}/reject", 'cr', ['reason' => 'race reject']);
    }

    /** Even workers: a waiter collects `$amount` on the order; odd workers: the cashier pays it in cash through POST /payments. */
    public function collectOrPay(int $index, array $waiter, array $cashier, string $orderId, string $facilityId, string $sessionId, string $amount): array
    {
        if ($index % 2 === 0) {
            return $this->call($index, [$waiter], 'POST', "/api/v1/orders/{$orderId}/collections", 'cp', ['tenderType' => 'CARD_TERMINAL', 'amount' => $amount, 'approvalCode' => 'CP-'.$index]);
        }

        return $this->call($index, [$cashier], 'POST', '/api/v1/payments', 'cp', [
            'facilityId' => $facilityId, 'cashSessionId' => $sessionId,
            'allocations' => [['orderId' => $orderId, 'amount' => $amount]], 'tenders' => [['tenderType' => 'CASH', 'amount' => $amount]],
        ]);
    }
}
