<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

final class DeviceWorkers
{
    /** Process $index tries to check the device out to staff[$index] at facilities[$index]. @return array{0: int, 1: string} */
    public function checkout(int $index, string $token, string $deviceId, array $staffIds, array $facilityIds): array
    {
        $request = Request::create("/api/v1/devices/{$deviceId}/checkout", 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'race-'.$index.'-'.bin2hex(random_bytes(6)),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['staffId' => $staffIds[$index], 'facilityId' => $facilityIds[$index]]));
        $response = app(Kernel::class)->handle($request);

        return [$response->getStatusCode(), substr((string) (json_decode((string) $response->getContent(), true)['debug']['message'] ?? $response->getContent()), 0, 300)];
    }
}
