<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Worker bodies run inside child processes by Tests\Support\Concurrent (each: own app + MySQL connection). */
final class BookingWorkers
{
    private function call(string $method, string $uri, string $token, array $body): array
    {
        $req = Request::create($uri, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'w-'.bin2hex(random_bytes(10)),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));
        $res = app(Kernel::class)->handle($req);
        $json = json_decode($res->getContent(), true) ?? [];

        return ['status' => $res->getStatusCode(), 'code' => $json['code'] ?? null, 'result' => $json['result'] ?? null, 'id' => $json['id'] ?? null, 'body' => $json];
    }

    public function hold(int $i, string $token, string $resourceId, string $start, string $end, int $qty = 1): array
    {
        return $this->call('POST', '/api/v1/bookings/hold', $token, ['resourceId' => $resourceId, 'start' => $start, 'end' => $end, 'quantity' => $qty, 'customer' => ['name' => "Racer {$i}"]]);
    }

    public function confirm(int $i, string $token, string $bookingId, int $rowVersion, string $amount): array
    {
        $req = Request::create("/api/v1/bookings/{$bookingId}/confirm", 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'c-'.bin2hex(random_bytes(10)), 'HTTP_IF_MATCH' => '"v'.$rowVersion.'"',
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['tenders' => [['tenderType' => 'CASH', 'amount' => $amount]]]));
        $res = app(Kernel::class)->handle($req);
        $json = json_decode($res->getContent(), true) ?? [];

        return ['status' => $res->getStatusCode(), 'code' => $json['code'] ?? null, 'result' => null, 'id' => $json['id'] ?? null];
    }

    public function redeem(int $i, string $token, string $qr, string $facilityId, int $quantity = 1): array
    {
        return $this->call('POST', "/api/v1/entitlement-tokens/{$qr}/redeem", $token, ['action' => 'ENTRY', 'facilityId' => $facilityId, 'quantity' => $quantity]);
    }

    public function release(int $i, string $token, string $entitlementId, string $itemId): array
    {
        return $this->call('POST', "/api/v1/entitlements/{$entitlementId}/release", $token, ['itemIds' => [$itemId]]);
    }
}
