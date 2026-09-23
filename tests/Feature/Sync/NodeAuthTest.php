<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Services\NodeCredentials;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProbeApplier;
use Tests\TwoNodeTestCase;

class NodeAuthTest extends TwoNodeTestCase
{
    private function push(array $events, ?string $token = null)
    {
        if ($this->currentNode() !== 'cloud') {
            $this->useNode('cloud');
        }

        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->rawToken))->postJson('/api/v1/sync/inbox', ['events' => $events]);
    }

    public function test_invalid_node_token_is_rejected_and_recorded_as_a_security_event(): void
    {
        $this->applierRegistry()->register('X', new ProbeApplier);
        $bad = NodeCredentials::generate()['token'];
        $r = $this->push([$this->envelope('X', Ids::uuid7(), 1, [])], $bad);
        $r->assertStatus(401)->assertJsonPath('code', 'invalid_node_token')->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(0, DB::table('inbox_event')->count(), 'nothing was processed');
        $ev = DB::table('security_event')->where('event_type', 'sync.node_auth_failed')->first();
        $this->assertNotNull($ev);
        $this->assertSame('WARNING', $ev->severity);
        $this->assertStringNotContainsString($bad, json_encode($ev), 'the presented token is never stored');

        // No token at all.
        $this->useNode('cloud');
        $this->postJson('/api/v1/sync/inbox', ['events' => []])->assertStatus(401)->assertJsonPath('code', 'invalid_node_token');
        $this->assertSame(2, DB::table('security_event')->where('event_type', 'sync.node_auth_failed')->count());
        // A staff-style token is not a node credential either.
        $this->withHeader('Authorization', 'Bearer not-a-node-token-but-long-enough-0123456789abcdef')->postJson('/api/v1/sync/heartbeat', [])->assertStatus(401);
    }

    public function test_repeated_bad_credentials_are_throttled(): void
    {
        $this->useNode('cloud');
        for ($i = 0; $i < 30; $i++) {
            $this->withHeader('Authorization', 'Bearer '.str_repeat('x', 40))->postJson('/api/v1/sync/inbox', [])->assertStatus(401);
        }
        $this->withHeader('Authorization', 'Bearer '.str_repeat('x', 40))->postJson('/api/v1/sync/inbox', [])->assertStatus(429);
        $this->assertSame(30, DB::table('security_event')->where('event_type', 'sync.node_auth_failed')->count(), 'a flood does not flood the security log');
    }

    public function test_valid_credential_is_rate_limited_per_minute_via_redis(): void
    {
        $this->applierRegistry()->register('X', new ProbeApplier);
        $this->override(['sync.rate_limit_per_minute' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->push([$this->envelope('X', Ids::uuid7(), 1, [])])->assertOk();
        }
        $r = $this->push([$this->envelope('X', Ids::uuid7(), 1, [])]);
        $r->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertNotNull($r->headers->get('Retry-After'));
        $this->assertSame(3, DB::table('inbox_event')->count());
    }

    public function test_credentials_are_stored_hashed_and_rotate_without_downtime(): void
    {
        $new = NodeCredentials::generate();
        $this->applierRegistry()->register('X', new ProbeApplier);
        // Rotation window: both hashes are accepted.
        $this->override(['sync.node_token_hashes' => 'local:'.$this->tokenHash.', local:'.$new['hash']]);
        $this->push([$this->envelope('X', Ids::uuid7(), 1, [])], $this->rawToken)->assertOk();
        $this->push([$this->envelope('X', Ids::uuid7(), 1, [])], $new['token'])->assertOk();
        // Old one retired.
        $this->override(['sync.node_token_hashes' => 'local:'.$new['hash']]);
        $this->push([$this->envelope('X', Ids::uuid7(), 1, [])], $this->rawToken)->assertStatus(401);
        $this->push([$this->envelope('X', Ids::uuid7(), 1, [])], $new['token'])->assertOk();
        // The config holds only hashes.
        $this->assertStringNotContainsString($new['token'], (string) config('sync.node_token_hashes'));
        $this->assertSame(hash('sha256', $new['token']), $new['hash']);
    }

    public function test_a_local_credential_cannot_impersonate_the_cloud_node(): void
    {
        $this->applierRegistry()->register('X', new ProbeApplier);
        $r = $this->push([$this->envelope('X', Ids::uuid7(), 1, [], 'cloud')]);
        $r->assertStatus(403)->assertJsonPath('code', 'node_mismatch');
        $this->assertSame(0, DB::table('inbox_event')->count());
    }

    public function test_a_credential_bound_to_a_site_is_refused_for_other_sites(): void
    {
        $this->applierRegistry()->register('X', new ProbeApplier);
        $this->override(['sync.node_token_hashes' => 'local@'.$this->site.':'.$this->tokenHash]);
        $this->push([$this->envelope('X', Ids::uuid7(), 1, [])])->assertOk();
        $other = $this->envelope('X', Ids::uuid7(), 1, []);
        $other['siteId'] = Ids::uuid7();
        $this->push([$other])->assertStatus(403)->assertJsonPath('code', 'node_site_mismatch');
    }

    public function test_the_local_node_does_not_accept_pushed_events(): void
    {
        $this->useNode('local');
        $this->override(['sync.node_token_hashes' => 'cloud:'.$this->tokenHash]);
        $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson('/api/v1/sync/inbox', ['events' => [$this->envelope('X', Ids::uuid7(), 1, [], 'cloud')]])
            ->assertStatus(404)->assertJsonPath('code', 'sync_inbox_disabled');
    }

    public function test_malformed_envelopes_are_422_and_stale_or_replayed_heartbeats_are_refused(): void
    {
        $this->push([['eventId' => 'nope']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->useNode('cloud');
        $h = fn (string $sentAt) => $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson('/api/v1/sync/heartbeat', ['nodeId' => $this->site, 'sentAt' => $sentAt]);
        $h(now('UTC')->subMinutes(10)->format('Y-m-d\TH:i:s.u\Z'))->assertStatus(422)->assertJsonPath('code', 'heartbeat_clock_skew');
        $this->assertSame(0, DB::table('site_health')->count(), 'a replayed old heartbeat cannot make the site look ONLINE');
        $h(now('UTC')->format('Y-m-d\TH:i:s.u\Z'))->assertOk()->assertJsonStructure(['ackAt', 'peerReachable', 'commandsPending', 'serverVersion']);
        $this->assertSame(1, DB::table('site_health')->count());
    }
}
