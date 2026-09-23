<?php

namespace Tests\Support;

use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Worker bodies executed inside child processes by Tests\Support\Concurrent (see worker.php). */
final class Workers
{
    public function auditAppend(int $index, string $org, string $site, int $count): int
    {
        for ($i = 0; $i < $count; $i++) {
            Audit::record("concurrent.{$index}", 'Thing', Ids::uuid7(), null, ['i' => $i], $org, $site);
        }

        return $count;
    }

    /** Fires the same idempotent POST; returns [status, replayed?, id]. */
    public function idempotentPost(int $index, string $token, string $key, string $name): array
    {
        $kernel = app(Kernel::class);
        $request = Request::create('/api/v1/_test/orgs?sleepMs=150', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => $key,
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['name' => $name]));
        $response = $kernel->handle($request);

        return [$response->getStatusCode(), $response->headers->get('Idempotent-Replayed'), json_decode($response->getContent(), true)['id'] ?? null];
    }
}
