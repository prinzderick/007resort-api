<?php

namespace Tests\Support;

use App\Support\Demo\DemoIds;
use Illuminate\Support\Facades\Artisan;

/** Helpers for tests that run against the seeded demo property (r007:demo-seed). Use inside a TestCase. */
trait DemoApi
{
    protected function seedDemo(): void
    {
        Artisan::call('r007:demo-seed');
    }

    /** Log in as a demo staff user (PIN 1234). @return array<string, mixed> AuthResult */
    protected function loginAs(string $username, ?string $deviceToken = null): array
    {
        $r = $this->withHeaders($deviceToken ? ['X-Device-Token' => $deviceToken] : [])
            ->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => $username, 'secret' => '1234']);
        $r->assertOk();

        return $r->json();
    }

    /** @return array{Authorization: string} plus optional X-Device-Token */
    protected function authHeaders(array $auth, ?string $deviceToken = null, ?string $idem = null): array
    {
        return array_filter([
            'Authorization' => 'Bearer '.$auth['accessToken'],
            'X-Device-Token' => $deviceToken,
            'Idempotency-Key' => $idem,
        ]);
    }

    protected function api(string $username, ?string $deviceToken = null): TestResponseBuilder
    {
        return new TestResponseBuilder($this, $this->loginAs($username, $deviceToken), $deviceToken);
    }

    protected function facilityId(string $code): string
    {
        return DemoIds::facility($code);
    }

    protected function deviceToken(string $code): string
    {
        return 'r7d_dev_'.strtolower($code);
    }
}
