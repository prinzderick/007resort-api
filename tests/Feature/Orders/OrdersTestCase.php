<?php

namespace Tests\Feature\Orders;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrdersFixture;
use Tests\Support\TestData;
use Tests\TestCase;

abstract class OrdersTestCase extends TestCase
{
    protected OrdersFixture $f;

    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = OrdersFixture::make();
    }

    protected function token(string $user): string
    {
        $this->flushHeaders();

        return $this->tokens[$user] ??= $this->postJson('/api/v1/auth/staff/login', ['username' => $user, 'password' => TestData::PASSWORD])->json('accessToken');
    }

    /** @param array<string, string> $headers */
    protected function api(string $user, string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        $headers += ['Idempotency-Key' => 'k-'.Str::random(24)];

        $this->flushHeaders(); // withHeaders() is sticky on the test case: never leak If-Match / step-up tokens into the next call

        return $this->withToken($this->token($user))->withHeaders($headers)->json($method, '/api/v1'.$uri, $body);
    }

    protected function etag(TestResponse $r): string
    {
        return (string) $r->headers->get('ETag');
    }

    /** Create a DRAFT order for the waiter with the given [productKey => qty] lines; returns the response. */
    protected function draft(array $lines = ['jollof' => 1], string $user = 'waiter', ?string $table = 'T1'): TestResponse
    {
        $body = ['facilityId' => $this->f->restaurant->id, 'lines' => []];
        if ($table) {
            $body['tableId'] = $this->f->tables[$table];
        }
        foreach ($lines as $key => $qty) {
            $body['lines'][] = ['productId' => $this->f->products[$key], 'quantity' => $qty];
        }

        return $this->api($user, 'POST', '/orders', $body)->assertStatus(201);
    }

    protected function send(string $orderId, string $etag, string $user = 'waiter'): TestResponse
    {
        return $this->api($user, 'POST', "/orders/{$orderId}/send", [], ['If-Match' => $etag]);
    }

    /** Supervisor (or anyone) authenticates on the caller's device: returns a single-use X-Step-Up-Token for a permission. */
    protected function stepUp(string $caller, string $approver, string $permission, ?string $entityId = null): string
    {
        return $this->withToken($this->token($caller))->postJson('/api/v1/auth/staff/step-up', array_filter([
            'credentialType' => 'PASSWORD', 'identifier' => $approver, 'secret' => TestData::PASSWORD, 'permission' => $permission, 'entityId' => $entityId,
        ]))->assertOk()->json('stepUpToken');
    }

    /** Sent order (lines routed) for the waiter; returns the SENT order response. */
    protected function sent(array $lines = ['jollof' => 1, 'chapman' => 1], string $table = 'T1'): TestResponse
    {
        $d = $this->draft($lines, 'waiter', $table);

        return $this->send($d->json('id'), $this->etag($d))->assertOk();
    }
}
