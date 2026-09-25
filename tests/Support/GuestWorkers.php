<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Child-process bodies for the guest checkout race tests (each process: own app + MySQL connection). */
final class GuestWorkers
{
    /**
     * Process i holds with actors[i]: ['guest', serviceToken, email] | ['customer', bearer] | ['staff', bearer].
     *
     * @param  list<array<int, string>>  $actors
     */
    public function holdAs(int $i, array $actors, string $resourceId, string $start, string $end): array
    {
        return $this->hold($actors[$i], $resourceId, $start, $end, 'h-'.$i.'-'.bin2hex(random_bytes(6)), $i);
    }

    /** Same guest email, process i takes resources[i] at the same slot (different resources: the race is on the per-contact cap). */
    public function holdSameContact(int $i, string $serviceToken, string $email, array $resourceIds, string $start, string $end): array
    {
        return $this->hold(['guest', $serviceToken, $email], $resourceIds[$i % count($resourceIds)], $start, $end, 'c-'.$i.'-'.bin2hex(random_bytes(6)), $i);
    }

    /** Every process: the SAME service token, the SAME Idempotency-Key, the SAME body. */
    public function holdSameKey(int $i, string $serviceToken, string $email, string $key, string $resourceId, string $start, string $end): array
    {
        return $this->hold(['guest', $serviceToken, $email], $resourceId, $start, $end, $key, 0);
    }

    private function hold(array $actor, string $resourceId, string $start, string $end, string $key, int $i): array
    {
        $body = ['resourceId' => $resourceId, 'start' => $start, 'end' => $end];
        $token = $actor[1];
        if ($actor[0] === 'guest') {
            $body['guest'] = ['name' => "Racer {$i}", 'email' => $actor[2] ?? "racer{$i}@example.test", 'phone' => '0803 000 '.sprintf('%04d', 1000 + $i), 'consentVersion' => 'v1'];
        } else {
            $body['customer'] = ['name' => "Racer {$i}"];
        }
        $req = Request::create('/api/v1/bookings/hold', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => $key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_IP' => '203.0.113.'.(10 + $i),
        ], json_encode($body));
        $res = app(Kernel::class)->handle($req);
        $json = json_decode($res->getContent(), true) ?? [];

        return ['status' => $res->getStatusCode(), 'code' => $json['code'] ?? null, 'id' => $json['id'] ?? null, 'ref' => $json['guestAccess']['reference'] ?? null, 'customerId' => $json['customerId'] ?? null];
    }
}
