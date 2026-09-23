<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

/** Authenticated request helper: `$this->api('manager1')->post('/staff', [...])` (adds bearer, device token, fresh Idempotency-Key). */
final class TestResponseBuilder
{
    public function __construct(private readonly TestCase $t, public readonly array $auth, private readonly ?string $deviceToken = null) {}

    public function headers(array $extra = []): array
    {
        return array_filter(['Authorization' => 'Bearer '.$this->auth['accessToken'], 'X-Device-Token' => $this->deviceToken] + $extra);
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->t->withHeaders($this->headers($headers))->getJson('/api/v1'.$uri);
    }

    public function post(string $uri, array $body = [], array $headers = [], bool $idem = true): TestResponse
    {
        return $this->t->withHeaders($this->headers($headers + ($idem ? ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(10))] : [])))->postJson('/api/v1'.$uri, $body);
    }

    public function put(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->t->withHeaders($this->headers($headers))->putJson('/api/v1'.$uri, $body);
    }

    public function patch(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->t->withHeaders($this->headers($headers))->patchJson('/api/v1'.$uri, $body);
    }

    public function delete(string $uri, array $headers = [], bool $idem = true): TestResponse
    {
        return $this->t->withHeaders($this->headers($headers + ($idem ? ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(10))] : [])))->deleteJson('/api/v1'.$uri);
    }
}
