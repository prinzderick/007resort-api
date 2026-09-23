<?php

namespace Tests\Feature\Membership;

use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipLifecycle;
use App\Domain\Membership\Services\MembershipScheduler;
use App\Domain\Membership\Services\MembershipService;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MembersFixtures;
use Tests\Support\TestData;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use MembersFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    private function buy(string $token, string $planId, array $extra = [], ?string $key = null)
    {
        return $this->postJson('/api/v1/memberships', [
            'planId' => $planId, 'customer' => ['name' => 'Ada Obi', 'phone' => '+234 803 000 1111', 'email' => 'ada@example.com'],
            'tenders' => [['tenderType' => 'CASH', 'amount' => '50000.0000']],
        ] + $extra, $this->idem($token, $key));
    }

    public function test_purchase_with_full_tender_activates_and_issues_cards_with_audit_and_outbox(): void
    {
        [, $tok] = $this->actor('reception', 'CASHIER');
        $plan = $this->plan();

        $res = $this->buy($tok, $plan->id)->assertCreated();
        $res->assertJsonPath('status', 'ACTIVE')->assertJsonPath('holderName', 'Ada Obi')->assertJsonPath('planName', 'Test plan');
        $this->assertNotEmpty($res->json('qrToken'));
        $this->assertCount(2, $res->json('cards'));
        $this->assertSame('50000.0000', $res->json('pricePaid'));
        $id = $res->json('id');

        $this->assertSame(['PENDING_PAYMENT', 'ACTIVE'], DB::table('membership_status_history')->where('membership_id', Ids::toBinary($id))->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'MembershipPurchased')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'MembershipStatusChanged')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('audit_log')->where('entity_id', Ids::toBinary($id))->count());
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_purchase_is_idempotent_and_replays_the_original(): void
    {
        [, $tok] = $this->actor('reception', 'CASHIER');
        $plan = $this->plan();
        $a = $this->buy($tok, $plan->id, key: 'k-1')->assertCreated();
        $b = $this->buy($tok, $plan->id, key: 'k-1')->assertCreated();
        $this->assertSame('true', $b->headers->get('Idempotent-Replayed'));
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, Membership::query()->count());
    }

    public function test_tender_total_must_equal_price(): void
    {
        [, $tok] = $this->actor('reception', 'CASHIER');
        $plan = $this->plan();
        $this->postJson('/api/v1/memberships', ['planId' => $plan->id, 'customer' => ['name' => 'X'], 'tenders' => [['tenderType' => 'CASH', 'amount' => '100.0000']]], $this->idem($tok))
            ->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $this->assertSame(0, Membership::query()->count(), 'failed request must roll back');
    }

    public function test_purchase_without_tenders_is_pending_payment_and_activates_once_on_payment_captured(): void
    {
        [, $tok] = $this->actor('reception', 'CASHIER');
        $plan = $this->plan();
        $res = $this->postJson('/api/v1/memberships', ['planId' => $plan->id, 'customer' => ['name' => 'Online Olu', 'email' => 'olu@example.com']], $this->idem($tok))->assertCreated();
        $res->assertJsonPath('status', 'PENDING_PAYMENT');
        $id = $res->json('id');
        $paymentId = Ids::uuid7();

        // Same call the (future) PaymentCaptured listener makes; replays are no-ops.
        $svc = app(MembershipService::class);
        $this->assertSame('ACTIVE', $svc->activateOnPayment($id, $paymentId, 'PSK-1')->status);
        $again = $svc->activateOnPayment($id, $paymentId, 'PSK-1');
        $this->assertSame('ACTIVE', $again->status);
        $this->assertSame(1, DB::table('membership_status_history')->where('membership_id', Ids::toBinary($id))->where('to_status', 'ACTIVE')->count());

        // and the Event listener route works with a plain event object.
        $m2 = $this->postJson('/api/v1/memberships', ['planId' => $plan->id, 'customer' => ['name' => 'Two']], $this->idem($tok))->json('id');
        event('App\\Domain\\Payments\\Events\\PaymentCaptured', [(object) ['paymentId' => Ids::uuid7(), 'metadata' => ['membershipId' => $m2], 'reference' => 'R2']]);
        $this->assertSame('ACTIVE', Membership::find($m2)->status);
    }

    public function test_same_customer_phone_reuses_the_customer(): void
    {
        [, $tok] = $this->actor('reception', 'CASHIER');
        $plan = $this->plan();
        $this->buy($tok, $plan->id)->assertCreated();
        $this->buy($tok, $plan->id)->assertCreated();
        $this->assertSame(1, DB::table('customer')->count());
        $this->assertSame(2, Membership::query()->count());
    }

    public function test_permissions_are_enforced(): void
    {
        [, $wait] = $this->actor('waiter', 'WAIT_STAFF');
        $plan = $this->plan();
        $this->buy($wait, $plan->id)->assertStatus(403)->assertJsonPath('permission', 'membership.sell');
        $this->getJson('/api/v1/memberships', ['Authorization' => 'Bearer '.$wait])->assertStatus(403);
        $this->postJson('/api/v1/memberships/plans', ['code' => 'X', 'name' => 'X', 'price' => '1', 'durationDays' => 1], $this->idem($wait))->assertStatus(403);

        [, $cashier] = $this->actor('cash', 'CASHIER');
        $id = $this->buy($cashier, $plan->id)->json('id');
        $this->postJson("/api/v1/memberships/{$id}/suspend", ['reason' => 'because'], $this->idem($cashier))->assertStatus(403)->assertJsonPath('permission', 'membership.manage');

        $this->postJson('/api/v1/memberships', [], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_facility_scoped_seller_cannot_sell_for_another_facility(): void
    {
        $spa = TestData::facility($this->t, 'spa');
        $gym = TestData::facility($this->t, 'gym');
        [, $tok] = $this->actor('spacash', 'CASHIER', 'FACILITY_UNIT', $spa->id);
        $plan = $this->plan();
        $this->buy($tok, $plan->id, ['facilityId' => $gym->id])->assertStatus(403);
        $this->buy($tok, $plan->id, ['facilityId' => $spa->id])->assertCreated();
    }

    public function test_status_transitions_are_guarded_and_recorded(): void
    {
        [, $mgr] = $this->actor('mgr');
        $plan = $this->plan();
        $id = $this->buy($mgr, $plan->id)->json('id');

        $this->postJson("/api/v1/memberships/{$id}/suspend", ['reason' => 'chargeback'], $this->idem($mgr))->assertOk()->assertJsonPath('status', 'SUSPENDED');
        $this->postJson("/api/v1/memberships/{$id}/reinstate", ['reason' => 'resolved'], $this->idem($mgr))->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/v1/memberships/{$id}/cancel", ['reason' => 'left resort'], $this->idem($mgr))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/v1/memberships/{$id}/reinstate", ['reason' => 'oops'], $this->idem($mgr))->assertStatus(409)->assertJsonPath('code', 'invalid_membership_transition');
        $this->postJson("/api/v1/memberships/{$id}/suspend", ['reason' => 'nope'], $this->idem($mgr))->assertStatus(409);
        $this->postJson("/api/v1/memberships/{$id}/renew", ['tenders' => [['tenderType' => 'CASH', 'amount' => '50000']]], $this->idem($mgr))->assertStatus(409);

        $hist = $this->getJson("/api/v1/memberships/{$id}/history", ['Authorization' => 'Bearer '.$mgr])->assertOk()->json('items');
        $this->assertSame(['CANCELLED', 'ACTIVE', 'SUSPENDED', 'ACTIVE', 'PENDING_PAYMENT'], array_column($hist, 'toStatus'));
    }

    public function test_renew_extends_from_term_end_resets_visits_and_needs_payment(): void
    {
        [, $mgr] = $this->actor('mgr');
        $plan = $this->plan(['visit_limit' => 3]);
        $created = $this->buy($mgr, $plan->id)->json();
        $id = $created['id'];
        DB::table('membership')->where('id', Ids::toBinary($id))->update(['visits_used' => 3]);

        $this->postJson("/api/v1/memberships/{$id}/renew", [], $this->idem($mgr))->assertStatus(422)->assertJsonPath('code', 'payment_required');
        $r = $this->postJson("/api/v1/memberships/{$id}/renew", ['tenders' => [['tenderType' => 'TRANSFER', 'amount' => '50000', 'reference' => 'TRF-9']]], $this->idem($mgr))->assertOk();
        $r->assertJsonPath('visitsUsed', 0)->assertJsonPath('renewalCount', 1)->assertJsonPath('status', 'ACTIVE');
        $this->assertGreaterThan(CarbonImmutable::parse($created['validUntil'])->addDays(29)->timestamp, CarbonImmutable::parse($r->json('validUntil'))->timestamp);
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'MembershipRenewed')->count());
    }

    public function test_scheduler_pending_renewal_grace_expire_and_idempotency(): void
    {
        [, $mgr] = $this->actor('mgr');
        $plan = $this->plan(['grace_period_days' => 3, 'renewal_notice_days' => 5]);
        $noGrace = $this->plan(['grace_period_days' => 0, 'renewal_notice_days' => 0]);
        $a = $this->buy($mgr, $plan->id)->json('id');
        $b = $this->buy($mgr, $noGrace->id)->json('id');
        $sched = app(MembershipScheduler::class);
        $now = CarbonImmutable::now('UTC');
        $set = fn (string $id, CarbonImmutable $until) => DB::table('membership')->where('id', Ids::toBinary($id))->update(['valid_until' => $until->format('Y-m-d H:i:s.u')]);
        $status = fn (string $id) => Membership::find($id)->status;

        // 1. inside the renewal notice window
        $set($a, $now->addDays(2));
        $this->assertSame(1, $sched->run($now)['pendingRenewal']);
        $this->assertSame('PENDING_RENEWAL', $status($a));
        $this->assertSame(['pendingRenewal' => 0, 'grace' => 0, 'expired' => 0], $sched->run($now), 'second run is a no-op');

        // 2. term ended 1 day ago, 3 days grace -> stays PENDING_RENEWAL with grace_until set, still usable
        $set($a, $now->subDay());
        $set($b, $now->subHour());
        $r = $sched->run($now);
        $this->assertSame(1, $r['grace']);
        $this->assertSame(1, $r['expired'], 'plan without grace expires straight away');
        $this->assertSame('PENDING_RENEWAL', $status($a));
        $this->assertNotNull(Membership::find($a)->grace_until);
        $this->assertSame('EXPIRED', $status($b));
        $this->assertSame(['pendingRenewal' => 0, 'grace' => 0, 'expired' => 0], $sched->run($now));

        // 3. grace over -> EXPIRED
        $later = $now->addDays(4);
        $this->assertSame(1, $sched->run($later)['expired']);
        $this->assertSame('EXPIRED', $status($a));
        $this->assertSame(['pendingRenewal' => 0, 'grace' => 0, 'expired' => 0], $sched->run($later));

        $this->assertSame(['ACTIVE', 'PENDING_RENEWAL', 'EXPIRED'], DB::table('membership_status_history')->where('membership_id', Ids::toBinary($a))->where('to_status', '!=', 'PENDING_PAYMENT')->orderBy('id')->pluck('to_status')->all());
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_expired_membership_can_be_renewed_from_now(): void
    {
        [, $mgr] = $this->actor('mgr');
        $plan = $this->plan();
        $id = $this->buy($mgr, $plan->id)->json('id');
        DB::table('membership')->where('id', Ids::toBinary($id))->update(['valid_until' => CarbonImmutable::now('UTC')->subDays(5)->format('Y-m-d H:i:s.u')]);
        app(MembershipScheduler::class)->run();
        $this->assertSame('EXPIRED', Membership::find($id)->status);
        $r = $this->postJson("/api/v1/memberships/{$id}/renew", ['tenders' => [['tenderType' => 'CASH', 'amount' => '50000']]], $this->idem($mgr))->assertOk();
        $this->assertSame('ACTIVE', $r->json('status'));
        $this->assertGreaterThan(CarbonImmutable::now('UTC')->addDays(29)->timestamp, CarbonImmutable::parse($r->json('validUntil'))->timestamp);
    }

    public function test_lifecycle_command_runs(): void
    {
        $this->artisan('r007:membership:lifecycle')->expectsOutputToContain('pendingRenewal=0')->assertSuccessful();
    }

    public function test_plan_management_and_listing(): void
    {
        [, $mgr] = $this->actor('mgr');
        $spa = TestData::facility($this->t, 'spa');
        $res = $this->postJson('/api/v1/memberships/plans', [
            'code' => 'gold', 'name' => 'Gold', 'price' => '150000', 'durationDays' => 30, 'visitLimit' => null, 'guestAllowance' => 2,
            'memberDiscountPercent' => 15, 'gracePeriodDays' => 5, 'facilityIds' => [$spa->id], 'bookingPrivileges' => ['priorityBooking' => true],
        ], $this->idem($mgr))->assertCreated();
        $res->assertJsonPath('code', 'GOLD')->assertJsonPath('price', '150000.0000')->assertJsonPath('facilityIds.0', $spa->id)->assertJsonPath('memberDiscountPercent', '15.00');
        $this->postJson('/api/v1/memberships/plans', ['code' => 'GOLD', 'name' => 'Dup', 'price' => '1', 'durationDays' => 1], $this->idem($mgr))->assertStatus(409)->assertJsonPath('code', 'plan_code_taken');
        $this->postJson('/api/v1/memberships/plans', ['code' => 'BAD', 'name' => 'Bad', 'price' => '12.5abc', 'durationDays' => 0], $this->idem($mgr))->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $id = $res->json('id');
        $this->patchJson("/api/v1/memberships/plans/{$id}", ['propertyWide' => true, 'price' => '160000.50'], $this->idem($mgr))->assertOk()->assertJsonPath('facilityIds', [])->assertJsonPath('price', '160000.5000');
        $list = $this->getJson('/api/v1/memberships/plans', ['Authorization' => 'Bearer '.$mgr])->assertOk();
        $this->assertCount(1, $list->json('items'));
        $this->assertNull($list->json('nextCursor'));
    }

    public function test_membership_list_search_and_get(): void
    {
        [, $mgr] = $this->actor('mgr');
        $plan = $this->plan();
        $id = $this->buy($mgr, $plan->id)->json('id');
        $h = ['Authorization' => 'Bearer '.$mgr];
        $this->getJson('/api/v1/memberships?q=Ada', $h)->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/v1/memberships?q=nobody', $h)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/v1/memberships?filter[status]=EXPIRED', $h)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/v1/memberships?filter[status]=ACTIVE', $h)->assertOk()->assertJsonCount(1, 'items');
        $this->getJson("/api/v1/memberships/{$id}", $h)->assertOk()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/memberships/'.Ids::uuid7(), $h)->assertStatus(404);
    }

    public function test_nfc_card_attach_and_revoke(): void
    {
        [, $mgr] = $this->actor('mgr');
        $plan = $this->plan();
        $a = $this->buy($mgr, $plan->id)->json('id');
        $b = $this->buy($mgr, $plan->id)->json('id');
        $card = $this->postJson("/api/v1/memberships/{$a}/cards", ['type' => 'NFC', 'uid' => '04:a2:24:5b'], $this->idem($mgr))->assertCreated();
        $card->assertJsonPath('identifier', '04A2245B');
        $this->postJson("/api/v1/memberships/{$b}/cards", ['type' => 'NFC', 'uid' => '04A2245B'], $this->idem($mgr))->assertStatus(409)->assertJsonPath('code', 'card_already_assigned');
        $this->postJson("/api/v1/memberships/{$a}/cards/{$card->json('id')}/revoke", ['status' => 'LOST'], $this->idem($mgr))->assertOk()->assertJsonPath('status', 'LOST');
    }

    public function test_lifecycle_service_rejects_unknown_membership(): void
    {
        $this->expectException(ApiProblem::class);
        app(MembershipLifecycle::class)->transition(Ids::uuid7(), 'ACTIVE', 'x');
    }
}
