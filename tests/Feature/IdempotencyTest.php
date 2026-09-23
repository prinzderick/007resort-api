<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestData;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    private string $token;

    private string $token2;

    protected function setUp(): void
    {
        parent::setUp();
        $t = TestData::tenant();
        TestData::staff($t, 'idem1');
        TestData::staff($t, 'idem2');
        $this->token = $this->postJson('/api/v1/auth/staff/login', ['username' => 'idem1', 'password' => TestData::PASSWORD])->json('accessToken');
        $this->token2 = $this->postJson('/api/v1/auth/staff/login', ['username' => 'idem2', 'password' => TestData::PASSWORD])->json('accessToken');
    }

    private function create(string $name, ?string $key, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->token)->withHeaders($key === null ? [] : ['Idempotency-Key' => $key])
            ->postJson('/api/v1/_test/orgs', ['name' => $name]);
    }

    public function test_replay_returns_original_response_and_does_not_double_apply(): void
    {
        $first = $this->create('Acme', 'key-1')->assertStatus(201);
        $again = $this->create('Acme', 'key-1')->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, DB::table('organization')->where('name', 'Acme')->count());
        $this->assertSame(1, DB::table('idempotency_record')->where('response_status', 201)->count());
    }

    public function test_same_key_different_body_is_422(): void
    {
        $this->create('One', 'key-2')->assertStatus(201);
        $this->create('Two', 'key-2')->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reuse');
        $this->assertSame(0, DB::table('organization')->where('name', 'Two')->count());
    }

    public function test_key_is_required(): void
    {
        $this->create('X', null)->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    }

    public function test_failed_requests_are_not_stored_and_can_be_retried_with_the_same_key(): void
    {
        $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'key-3'])->postJson('/api/v1/_test/orgs', [])->assertStatus(422);
        $this->assertSame(0, DB::table('idempotency_record')->count());
        $this->create('Fixed', 'key-3')->assertStatus(201);
    }

    public function test_keys_are_isolated_per_actor(): void
    {
        $a = $this->create('Shared', 'same-key', $this->token)->assertStatus(201);
        $b = $this->create('Shared', 'same-key', $this->token2)->assertStatus(201);
        $this->assertNotSame($a->json('id'), $b->json('id'));
        $this->assertArrayNotHasKey('Idempotent-Replayed', $b->headers->all());
        $this->assertSame(2, DB::table('organization')->where('name', 'Shared')->count());
    }

    public function test_response_and_effect_commit_atomically(): void
    {
        // Handler creates an org and returns >=400 => rolled back entirely, nothing stored.
        Route::middleware(['api', 'auth:staff', 'idempotent'])->post('api/v1/_test/half', function () {
            Organization::create(['name' => 'Half']);

            return response()->json(['x' => 1], 409);
        });
        $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'half-1'])->postJson('/api/v1/_test/half')->assertStatus(409);
        $this->assertSame(0, DB::table('organization')->where('name', 'Half')->count());
        $this->assertSame(0, DB::table('idempotency_record')->count());
    }
}
