<?php

namespace Tests\Feature\Membership;

use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Support\CardCodec;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MembersFixtures;
use Tests\Support\TestData;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    use MembersFixtures;

    private string $mgr;

    private string $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        [, $this->mgr] = $this->actor('mgr');
        [, $this->scanner] = $this->actor('gate', 'WAIT_STAFF');
    }

    private function member($plan, string $name = 'Ada'): array
    {
        $r = $this->postJson('/api/v1/memberships', ['planId' => $plan->id, 'customer' => ['name' => $name, 'phone' => '080'.random_int(10000000, 99999999)], 'tenders' => [['tenderType' => 'CASH', 'amount' => $plan->price]]], $this->idem($this->mgr))->assertCreated();

        return $r->json();
    }

    private function scan(string $facilityId, array $body, ?string $token = null)
    {
        return $this->postJson('/api/v1/memberships/validate', ['facilityId' => $facilityId] + $body, ['Authorization' => 'Bearer '.($token ?? $this->scanner), 'Accept' => 'application/json']);
    }

    public function test_valid_scan_consumes_a_visit_and_returns_discount_entitlement(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $plan = $this->plan(['visit_limit' => 2, 'member_discount_percent' => '12.50']);
        $m = $this->member($plan);

        $r = $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertOk();
        $r->assertJsonPath('valid', true)->assertJsonPath('reason', null)->assertJsonPath('consumed', true)->assertJsonPath('visitNumber', 1)
            ->assertJsonPath('entitlement.discountPercent', '12.50')->assertJsonPath('entitlement.visitsRemaining', 1)->assertJsonPath('membership.visitsUsed', 1);
        $this->assertSame(1, DB::table('membership_usage')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'MembershipUsageRecorded')->count());

        $this->scan($pool->id, ['membershipNumber' => strtolower($m['number'])])->assertOk()->assertJsonPath('visitNumber', 2)->assertJsonPath('entitlement.visitsRemaining', 0);
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('reason', 'VISIT_LIMIT_REACHED');
        $this->assertSame(2, DB::table('membership_usage')->count());
    }

    public function test_check_only_does_not_consume(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->member($this->plan(['visit_limit' => 1]));
        $this->scan($pool->id, ['qrToken' => $m['qrToken'], 'consume' => false])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('consumed', false);
        $this->assertSame(0, DB::table('membership_usage')->count());
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', true);
    }

    public function test_unknown_forged_and_revoked_credentials_are_not_found(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->member($this->plan());
        $this->scan($pool->id, ['qrToken' => 'garbage'])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('reason', 'NOT_FOUND');
        $forged = 'M1.'.str_replace('-', '', $m['id']).'.'.str_repeat('a', 20);
        $this->scan($pool->id, ['qrToken' => $forged])->assertJsonPath('reason', 'NOT_FOUND');
        $this->scan($pool->id, ['nfcUid' => 'DEADBEEF'])->assertJsonPath('reason', 'NOT_FOUND');

        $card = $this->postJson("/api/v1/memberships/{$m['id']}/cards", ['type' => 'NFC', 'uid' => 'AABBCCDD'], $this->idem($this->mgr))->json();
        $this->scan($pool->id, ['nfcUid' => 'aa:bb:cc:dd'])->assertJsonPath('valid', true);
        $this->postJson("/api/v1/memberships/{$m['id']}/cards/{$card['id']}/revoke", [], $this->idem($this->mgr))->assertOk();
        $this->scan($pool->id, ['nfcUid' => 'AABBCCDD'])->assertJsonPath('reason', 'NOT_FOUND');
        $this->assertSame($m['qrToken'], CardCodec::qrToken($m['id']));
    }

    public function test_status_and_term_reasons(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $plan = $this->plan();
        $m = $this->member($plan);
        $id = $m['id'];
        $bin = Ids::toBinary($id);

        $this->postJson("/api/v1/memberships/{$id}/suspend", ['reason' => 'hold'], $this->idem($this->mgr))->assertOk();
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('reason', 'SUSPENDED');
        $this->postJson("/api/v1/memberships/{$id}/reinstate", ['reason' => 'resolved'], $this->idem($this->mgr))->assertOk();

        // term over but scheduler has not run yet -> still refused (validator checks dates itself)
        DB::table('membership')->where('id', $bin)->update(['valid_until' => CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', false)->assertJsonPath('reason', 'EXPIRED');

        // ...but inside the grace window it is admitted and flagged
        DB::table('membership')->where('id', $bin)->update(['grace_until' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d H:i:s.u')]);
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', true)->assertJsonPath('entitlement.inGrace', true);

        DB::table('membership')->where('id', $bin)->update(['status' => 'EXPIRED']);
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('reason', 'EXPIRED');

        $pending = $this->postJson('/api/v1/memberships', ['planId' => $plan->id, 'customer' => ['name' => 'P']], $this->idem($this->mgr))->json();
        $this->scan($pool->id, ['qrToken' => $pending['qrToken']])->assertJsonPath('reason', 'NOT_ACTIVE');
    }

    public function test_coverage_including_descendants_and_discount_override(): void
    {
        $spa = TestData::facility($this->t, 'spa');
        $massage = TestData::facility($this->t, 'massage', $spa->id);
        $gym = TestData::facility($this->t, 'gym');
        $plan = $this->plan(['member_discount_percent' => '10.00'], [$spa->id]);
        DB::table('plan_coverage')->where('plan_id', Ids::toBinary($plan->id))->update(['discount_percent' => '25.00']);
        $m = $this->member($plan);

        $this->scan($spa->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', true)->assertJsonPath('entitlement.discountPercent', '25.00');
        $this->scan($massage->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', true)->assertJsonPath('entitlement.discountPercent', '25.00');
        $this->scan($gym->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', false)->assertJsonPath('reason', 'WRONG_FACILITY');
        $this->assertSame(2, Membership::find($m['id'])->visits_used, 'a refused scan must not consume a visit');
    }

    public function test_guest_allowance(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->member($this->plan(['guest_allowance' => 2]));
        $this->scan($pool->id, ['qrToken' => $m['qrToken'], 'guests' => 3])->assertJsonPath('valid', false)->assertJsonPath('reason', 'GUEST_LIMIT_EXCEEDED');
        $this->scan($pool->id, ['qrToken' => $m['qrToken'], 'guests' => 2])->assertJsonPath('valid', true)->assertJsonPath('entitlement.guestsAdmitted', 2);
        $this->assertSame(2, (int) DB::table('membership_usage')->value('guests'));
        $this->assertSame(1, Membership::find($m['id'])->visits_used);
    }

    public function test_scanner_retry_with_same_client_ref_does_not_double_consume(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->member($this->plan(['visit_limit' => 5]));
        $a = $this->scan($pool->id, ['qrToken' => $m['qrToken'], 'clientRef' => 'scan-1'])->assertOk();
        $b = $this->scan($pool->id, ['qrToken' => $m['qrToken'], 'clientRef' => 'scan-1'])->assertOk();
        $b->assertJsonPath('valid', true)->assertJsonPath('duplicate', true)->assertJsonPath('consumed', false)->assertJsonPath('visitNumber', 1);
        $this->assertSame(1, Membership::find($m['id'])->visits_used);
        $this->assertSame(1, DB::table('membership_usage')->count());
        $this->assertTrue($a->json('consumed'));
    }

    public function test_validate_requires_permission_at_that_facility_and_input(): void
    {
        $spa = TestData::facility($this->t, 'spa');
        $gym = TestData::facility($this->t, 'gym');
        [, $spaScanner] = $this->actor('spascan', 'CASHIER', 'FACILITY_UNIT', $spa->id);
        $m = $this->member($this->plan());
        $this->scan($gym->id, ['qrToken' => $m['qrToken']], $spaScanner)->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->scan($spa->id, ['qrToken' => $m['qrToken']], $spaScanner)->assertOk();

        [, $noPerm] = $this->actorWith('nobody', ['order.create']);
        $this->scan($spa->id, ['qrToken' => $m['qrToken']], $noPerm)->assertStatus(403);
        $this->postJson('/api/v1/memberships/validate', ['qrToken' => $m['qrToken']], ['Authorization' => 'Bearer '.$this->scanner])->assertStatus(400)->assertJsonPath('code', 'scope_required');
        $this->postJson('/api/v1/memberships/validate', [], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_renewed_term_restarts_visit_numbering(): void
    {
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->member($this->plan(['visit_limit' => 1]));
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', true);
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('reason', 'VISIT_LIMIT_REACHED');
        $this->postJson("/api/v1/memberships/{$m['id']}/renew", ['tenders' => [['tenderType' => 'CASH', 'amount' => '50000']]], $this->idem($this->mgr))->assertOk();
        $this->scan($pool->id, ['qrToken' => $m['qrToken']])->assertJsonPath('valid', true)->assertJsonPath('visitNumber', 1);
        $this->assertSame([0, 1], DB::table('membership_usage')->orderBy('term')->pluck('term')->map(fn ($v) => (int) $v)->all());
    }
}
