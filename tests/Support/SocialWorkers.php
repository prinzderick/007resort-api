<?php

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Child-process bodies for the social sign-in race tests (each process: own app + MySQL connection). */
final class SocialWorkers
{
    /** Every process posts the SAME claims (or bodies[i] when several are given). */
    public function login(int $i, string $serviceToken, array $bodies): array
    {
        $body = $bodies[$i % count($bodies)];
        $req = Request::create('/api/v1/public/customers/social/login', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$serviceToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));
        $res = app(Kernel::class)->handle($req);
        $json = json_decode($res->getContent(), true) ?? [];

        return ['status' => $res->getStatusCode(), 'code' => $json['code'] ?? null, 'customerId' => $json['customer']['id'] ?? null, 'isNew' => $json['isNewCustomer'] ?? null, 'linked' => $json['linkedExisting'] ?? null, 'detail' => $json['detail'] ?? null];
    }
}
