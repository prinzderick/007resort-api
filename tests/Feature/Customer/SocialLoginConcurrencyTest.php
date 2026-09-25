<?php

namespace Tests\Feature\Customer;

use App\Domain\Customer\Services\ServiceTokenService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\CustomerHelpers;
use Tests\Support\SocialHelpers;
use Tests\Support\SocialWorkers;
use Tests\Support\TestData;

/** REAL concurrency on MySQL: simultaneous first logins must yield exactly one customer / identity, and everyone a session. */
class SocialLoginConcurrencyTest extends ConcurrentTestCase
{
    use CustomerHelpers, SocialHelpers;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $t = TestData::tenant();
        $this->token = app(ServiceTokenService::class)->create('race', $t['org'], null, null, 'public.read,customer.social')['token'];
        foreach (['SOCIAL_PROVIDERS_ENABLED' => 'google,facebook', 'SOCIAL_TRUSTED_EMAIL_PROVIDERS' => 'google'] as $k => $v) { // children boot from the environment
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
    }

    protected function tearDown(): void
    {
        foreach (['SOCIAL_PROVIDERS_ENABLED', 'SOCIAL_TRUSTED_EMAIL_PROVIDERS'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $raw */
    private function results(array $raw): array
    {
        foreach ($raw as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }

        return array_map(fn ($r) => $r['result'], $raw);
    }

    public function test_simultaneous_first_logins_of_the_same_identity_create_one_customer(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $body = $this->claims(['providerUserId' => "race-{$round}", 'email' => "race{$round}@example.test"]);
            $res = $this->results(Concurrent::run(6, SocialWorkers::class, 'login', [$this->token, [$body]]));
            $this->assertCount(6, array_filter($res, fn ($x) => in_array($x['status'], [200, 201], true)), json_encode($res));
            $this->assertSame(1, count(array_filter($res, fn ($x) => $x['status'] === 201)), 'exactly one creator: '.json_encode($res));
            $this->assertCount(1, array_unique(array_column($res, 'customerId')));
            $this->assertSame(1, DB::table('customer')->where('email', "race{$round}@example.test")->count());
            $this->assertSame(1, DB::table('customer_identity')->where('provider_user_id', "race-{$round}")->count());
            $this->assertSame(1, DB::table('audit_log')->where('action', 'customer.social.register')->count() - $round);
        }
    }

    public function test_simultaneous_first_logins_of_different_identities_on_the_same_verified_email_never_duplicate_the_customer(): void
    {
        $g = $this->claims(['providerUserId' => 'g-A', 'email' => 'same@example.test']);
        $g2 = $this->claims(['providerUserId' => 'g-B', 'email' => 'same@example.test']);
        $res = $this->results(Concurrent::run(6, SocialWorkers::class, 'login', [$this->token, [$g, $g2]]));
        $this->assertSame(1, DB::table('customer')->where('email', 'same@example.test')->count());
        $this->assertSame(1, DB::table('customer_account')->where('login_email', 'same@example.test')->count());
        $this->assertSame(1, DB::table('customer_identity')->count(), 'UNIQUE(customer_id, provider): the second google account is refused'); // one wins, the other identity_conflict
        $codes = array_count_values(array_map(fn ($x) => $x['status'], $res));
        $this->assertSame(0, $codes[500] ?? 0, json_encode($res));
        foreach ($res as $x) {
            $this->assertContains($x['status'], [200, 201, 409], json_encode($x));
        }
    }

    public function test_simultaneous_login_and_link_of_a_walk_in_customer(): void
    {
        $org = DB::table('organization')->value('id');
        DB::table('customer')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $org, 'full_name' => 'Walk In', 'email' => 'walk@example.test']);
        $body = $this->claims(['providerUserId' => 'walk-g', 'email' => 'walk@example.test']);
        $res = $this->results(Concurrent::run(5, SocialWorkers::class, 'login', [$this->token, [$body]]));
        $this->assertCount(5, array_filter($res, fn ($x) => $x['status'] === 200), json_encode($res));
        $this->assertSame(1, DB::table('customer')->where('email', 'walk@example.test')->count());
        $this->assertSame(1, DB::table('customer_account')->count());
        $this->assertSame(1, DB::table('customer_identity')->count());
    }
}
