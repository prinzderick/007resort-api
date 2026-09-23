<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Child-process bodies for the customer race tests (each process: own app + MySQL connection). */
final class CustomerWorkers
{
    /** Process i holds as tokens[i] (staff or customer bearer). */
    public function holdAs(int $i, array $tokens, string $resourceId, string $start, string $end): array
    {
        return $this->post('/api/v1/bookings/hold', $tokens[$i], 'h-'.$i.'-'.bin2hex(random_bytes(6)), ['resourceId' => $resourceId, 'start' => $start, 'end' => $end, 'customer' => ['name' => "Racer {$i}"]]);
    }

    /** Every process uses the SAME token and the SAME Idempotency-Key. */
    public function holdSameKey(int $i, string $token, string $key, string $resourceId, string $start, string $end): array
    {
        return $this->post('/api/v1/bookings/hold', $token, $key, ['resourceId' => $resourceId, 'start' => $start, 'end' => $end]);
    }

    private function post(string $uri, string $token, string $key, array $body): array
    {
        $req = Request::create($uri, 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => $key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));
        $res = app(Kernel::class)->handle($req);
        $json = json_decode($res->getContent(), true) ?? [];

        return ['status' => $res->getStatusCode(), 'code' => $json['code'] ?? null, 'id' => $json['id'] ?? null, 'source' => $json['source'] ?? null];
    }
}
