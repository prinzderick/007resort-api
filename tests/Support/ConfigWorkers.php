<?php

namespace Tests\Support;

use App\Support\Ids;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ConfigWorkers
{
    /** PUTs operating rules with the SAME If-Match (independent admins editing at once). @return array{0: int, 1: string} */
    public function putRules(int $index, string $token, string $facilityId, string $version, string $value): array
    {
        $request = Request::create("/api/v1/facilities/{$facilityId}/operating-rules", 'PUT', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IF_MATCH' => '"'.$version.'"', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['rules' => ['approval_threshold_amount' => $value], 'confirm' => true]));
        $response = app(Kernel::class)->handle($request);

        return [$response->getStatusCode(), substr((string) $response->getContent(), 0, 300)];
    }

    /** POSTs the same facility code from N clients (fresh Idempotency-Key each). @return array{0: int, 1: string} */
    public function createFacility(int $index, string $token, string $code): array
    {
        $request = Request::create('/api/v1/organization/facilities', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'fac-race-'.$index.'-'.bin2hex(random_bytes(6)), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['code' => $code, 'name' => 'Racer '.$index, 'templateKey' => 'CAFE']));
        $response = app(Kernel::class)->handle($request);

        return [$response->getStatusCode(), substr((string) $response->getContent(), 0, 300)];
    }

    /** Move the two facilities under each other at the same time. @return array{0: int, 1: string} */
    public function move(int $index, string $token, string $facilityId, string $parentId, string $version): array
    {
        $request = Request::create("/api/v1/organization/facilities/{$facilityId}/move", 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IF_MATCH' => '"'.$version.'"', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['parentId' => $parentId]));
        $response = app(Kernel::class)->handle($request);

        return [$response->getStatusCode(), substr((string) $response->getContent(), 0, 300)];
    }

    /** Even workers move A under B, odd workers move B under A (each reads the current version first). @return array{0: int, 1: string} */
    public function moveOpposite(int $index, string $token, string $a, string $b): array
    {
        [$child, $parent] = $index % 2 === 0 ? [$a, $b] : [$b, $a];
        $version = (string) DB::table('facility_unit')->where('id', Ids::toBinary($child))->value('row_version');

        return $this->move($index, $token, $child, $parent, $version);
    }
}
