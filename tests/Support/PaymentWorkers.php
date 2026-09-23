<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Worker bodies run inside child PHP processes (own app, own MySQL connection) by Tests\Support\Concurrent. */
final class PaymentWorkers
{
    /** Fire one JSON request through the real HTTP kernel. @return array{0: int, 1: mixed, 2: ?string} status, body, replay header */
    public function call(int $index, string $method, string $uri, ?string $token, ?string $key, ?array $body): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ($key !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $res = app(Kernel::class)->handle(Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body)));

        return [$res->getStatusCode(), json_decode($res->getContent(), true), $res->headers->get('Idempotent-Replayed')];
    }

    /** Same request but each worker uses its own Idempotency-Key ("$keyPrefix-$index"). */
    public function callOwnKey(int $index, string $method, string $uri, string $token, string $keyPrefix, array $body): array
    {
        return $this->call($index, $method, $uri, $token, $keyPrefix.'-'.$index, $body);
    }

    /** A Paystack webhook delivery. Paystack "confirms" $kobo for the reference (sandbox fake). */
    public function webhook(int $index, string $body, int $kobo, string $status = 'success'): array
    {
        PaystackFakes::configure();
        PaystackFakes::http($status, $kobo);
        $sig = PaystackFakes::sign($body);
        $res = app(Kernel::class)->handle(Request::create('/api/v1/payments/webhooks/paystack', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig,
        ], $body));

        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    }

    /** Half the workers settle the tab, half pay order A directly (they contend on the same order lock). */
    public function mixed(int $index, string $token, string $tabId, array $tabBody, array $orderBody): array
    {
        return $index % 2 === 0
            ? $this->call($index, 'POST', "/api/v1/tabs/{$tabId}/settle", $token, "mix-$index", $tabBody)
            : $this->call($index, 'POST', '/api/v1/payments', $token, "mix-$index", $orderBody);
    }

    /** Even workers try a full refund, odd workers a reversal, of the same payment. */
    public function refundOrReverse(int $index, string $token, string $paymentId): array
    {
        return $index % 2 === 0
            ? $this->call($index, 'POST', "/api/v1/payments/{$paymentId}/refund", $token, "rr-$index", ['amount' => '5000.0000', 'reason' => 'race refund'])
            : $this->call($index, 'POST', "/api/v1/payments/{$paymentId}/reversal", $token, "rr-$index", ['reason' => 'race reversal']);
    }

    /** Worker 0 closes the cash session (counting whatever it holds); the others each pay one order in cash into it. */
    public function payOrClose(int $index, string $token, string $sessionId, string $facilityId, array $orderIds): array
    {
        if ($index === 0) {
            usleep(30000);

            return $this->call($index, 'POST', "/api/v1/cash-sessions/{$sessionId}/close", $token, 'close-1', ['countedCash' => '1000.0000']);
        }

        return $this->call($index, 'POST', '/api/v1/payments', $token, "pc-$index", [
            'facilityId' => $facilityId, 'cashSessionId' => $sessionId, 'allocations' => [['orderId' => $orderIds[$index - 1], 'amount' => '1500.0000']],
            'tenders' => [['tenderType' => 'CASH', 'amount' => '1500.0000']],
        ]);
    }

    /** Worker i posts bodies[i]. */
    public function eachBody(int $index, string $token, array $bodies): array
    {
        return $this->call($index, 'POST', '/api/v1/payments', $token, "each-$index", $bodies[$index]);
    }
}
