<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

final class RoleWorkers
{
    /** POSTs the same role grant (fresh Idempotency-Key each time, like independent clients). @return array{0: int, 1: string} HTTP status, body excerpt */
    public function grant(int $index, string $token, string $staffId, string $roleId, string $facilityId): array
    {
        $request = Request::create("/api/v1/staff/{$staffId}/role-assignments", 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'grant-race-'.$index.'-'.bin2hex(random_bytes(6)),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['roleId' => $roleId, 'scopeType' => 'FACILITY', 'scopeId' => $facilityId]));

        $response = app(Kernel::class)->handle($request);

        return [$response->getStatusCode(), substr((string) (json_decode((string) $response->getContent(), true)['debug']['message'] ?? $response->getContent()), 0, 400)];
    }
}
