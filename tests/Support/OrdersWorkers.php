<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Child-process worker: one authenticated JSON API call through the real HTTP kernel (own app + MySQL connection). */
final class OrdersWorkers
{
    /**
     * @param  array<string, string>  $headers  extra headers; if `Idempotency-Key` is absent a unique one is generated per worker
     * @return array{status: int, body: mixed, replayed: ?string}
     */
    public function call(int $index, string $token, string $method, string $uri, array $body = [], array $headers = []): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $headers += ['Idempotency-Key' => 'w'.$index.'-'.bin2hex(random_bytes(8))];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $request = Request::create('/api/v1'.$uri, $method, [], [], [], $server, $body === [] ? null : json_encode($body));
        $response = app(Kernel::class)->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'replayed' => $response->headers->get('Idempotent-Replayed')];
    }

    /** Even workers send the order, odd workers add a line: all with the same If-Match (a race between add-line and send). */
    public function sendOrAddLine(int $index, string $token, string $orderId, string $etag, string $productId): array
    {
        return $index % 2 === 0
            ? $this->call($index, $token, 'POST', "/orders/{$orderId}/send", [], ['If-Match' => $etag])
            : $this->call($index, $token, 'POST', "/orders/{$orderId}/lines", ['productId' => $productId, 'quantity' => 1], ['If-Match' => $etag]);
    }
}
