<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Services\CollectionService;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\CollectionWorld;
use Tests\TestCase;

/** Waiter cash-in-hand ledger and the handover to the cashier (docs/WAITER_COLLECTION.md section 6). */
class CashHandoverTest extends TestCase
{
    use CollectionWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCollectionWorld(cashHolding: true);
    }

    private function collectCash(string $amount): string
    {
        $order = $this->billedOrder($amount);

        return $this->collect($order, ['tenderType' => 'CASH', 'amount' => $amount])->assertCreated()->json('payment.id');
    }

    private function declare(string $amount, ?string $token = null, array $extra = [])
    {
        return $this->postJson('/api/v1/cash-handovers', ['declaredAmount' => $amount] + $extra, $this->auth($token ?? $this->waiterToken));
    }

    public function test_exact_handover_reduces_cash_in_hand_and_is_audited(): void
    {
        $this->collectCash('9000.0000');
        $this->collectCash('3000.0000');
        $pos = $this->getJson("/api/v1/staff/{$this->waiter->id}/cash-in-hand", $this->auth($this->waiterToken, null))->assertOk();
        $this->assertSame('12000.0000', $pos->json('cashInHand'));
        $this->assertSame(2, $pos->json('pendingCollections'));
        $this->assertSame('12000.0000', $pos->json('pendingCollectionsAmount'));
        $this->assertNotNull($pos->json('oldestUncollectedAt'));

        $h = $this->declare('9000.0000', null, ['note' => 'lunch takings'])->assertCreated();
        $id = $h->json('id');
        $this->assertSame('PENDING_RECEIPT', $h->json('status'));
        $this->assertSame('12000.0000', $h->json('expectedInHand'));
        $this->assertSame('12000.0000', app(CollectionService::class)->cashInHand($this->waiter->id), 'not reduced until the cashier receives it');

        // only a cash_handover.receive holder, never the waiter
        $this->postJson("/api/v1/cash-handovers/{$id}/receive", ['countedAmount' => '9000.0000'], $this->auth($this->waiterToken))->assertStatus(403);
        $namedManager = $this->roleToken('mgrnamed', ['cash_handover.view', 'order.view'], 'Manager');
        $this->postJson("/api/v1/cash-handovers/{$id}/receive", ['countedAmount' => '9000.0000'], $this->auth($namedManager))->assertStatus(403);
        $r = $this->postJson("/api/v1/cash-handovers/{$id}/receive", ['countedAmount' => '9000.0000'], $this->auth($this->cashierToken))->assertOk();
        $this->assertSame('RECEIVED', $r->json('status'));
        $this->assertSame('0.0000', $r->json('variance'));
        $this->assertSame('EXACT', $r->json('varianceKind'));
        $this->assertSame($this->cashier->id, $r->json('receivedByStaffId'));
        $this->assertSame('3000.0000', app(CollectionService::class)->cashInHand($this->waiter->id));
        $this->assertSame(0, DB::table('security_event')->where('event_type', 'cash_handover.variance')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'cash_handover.receive')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'CashHandoverRecorded')->count());
        $this->postJson("/api/v1/cash-handovers/{$id}/receive", ['countedAmount' => '9000.0000'], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'already_received');
        $this->getJson("/api/v1/cash-handovers/{$id}", $this->auth($this->waiterToken, null))->assertOk()->assertJsonPath('status', 'RECEIVED');
        $this->getJson("/api/v1/cash-handovers/{$id}", $this->auth($this->waiter2Token, null))->assertStatus(403);
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_variance_short_and_over_small_is_received_large_needs_supervisor_signoff(): void
    {
        $this->collectCash('5000.0000');
        // short by 200 (below the 500 default): received, variance recorded, alert raised
        $h = $this->declare('2000.0000')->assertCreated()->json('id');
        $r = $this->postJson("/api/v1/cash-handovers/{$h}/receive", ['countedAmount' => '1800.0000', 'note' => 'N200 note missing'], $this->auth($this->cashierToken))->assertOk();
        $this->assertSame('RECEIVED', $r->json('status'));
        $this->assertSame('-200.0000', $r->json('variance'));
        $this->assertSame('SHORT', $r->json('varianceKind'));
        $this->assertFalse($r->json('requiresSignoff'));
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'cash_handover.variance')->where('severity', 'WARNING')->count());

        // over by 700 (above 500): needs sign-off; the receiver cannot sign their own count
        $h2 = $this->declare('1000.0000')->assertCreated()->json('id');
        $r2 = $this->postJson("/api/v1/cash-handovers/{$h2}/receive", ['countedAmount' => '1700.0000'], $this->auth($this->cashierToken))->assertOk();
        $this->assertSame('PENDING_SIGNOFF', $r2->json('status'));
        $this->assertSame('700.0000', $r2->json('variance'));
        $this->assertSame('OVER', $r2->json('varianceKind'));
        $this->assertSame(1, DB::table('security_event')->where('severity', 'CRITICAL')->where('event_type', 'cash_handover.variance')->count());
        $this->postJson("/api/v1/cash-handovers/{$h2}/signoff", ['note' => 'ok'], $this->auth($this->cashierToken))->assertStatus(403);
        $sup = $this->supervisorToken;
        $this->postJson("/api/v1/cash-handovers/{$h2}/signoff", ['note' => 'recounted, customer tipped'], $this->auth($sup))->assertOk()->assertJsonPath('status', 'SIGNED_OFF');
        $this->postJson("/api/v1/cash-handovers/{$h2}/signoff", [], $this->auth($sup))->assertOk()->assertJsonPath('status', 'SIGNED_OFF'); // idempotent
        $this->assertSame('2000.0000', app(CollectionService::class)->cashInHand($this->waiter->id));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'cash_handover.signoff')->count());

        // the threshold is a facility rule
        $this->setRuleFlush('cash_handover_max_variance', '50');
        $h3 = $this->declare('500.0000')->assertCreated()->json('id');
        $this->postJson("/api/v1/cash-handovers/{$h3}/receive", ['countedAmount' => '400.0000'], $this->auth($this->cashierToken))->assertOk()->assertJsonPath('status', 'PENDING_SIGNOFF');
        $pos = $this->getJson("/api/v1/staff/{$this->waiter->id}/cash-in-hand", $this->auth($this->waiterToken, null))->assertOk();
        $this->assertSame('100.0000', $pos->json('unsignedShortfall'));
        $this->assertSame(1, $pos->json('openHandovers'));
    }

    public function test_declared_amount_cannot_exceed_cash_in_hand_and_replays_by_client_id(): void
    {
        $this->collectCash('4000.0000');
        $this->declare('4000.0001')->assertStatus(422)->assertJsonPath('code', 'amount_mismatch');
        $id = Ids::uuid7();
        $this->declare('3000.0000', null, ['id' => $id])->assertCreated();
        $this->declare('3000.0000', null, ['id' => $id])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->declare('3500.0000', null, ['id' => $id])->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
        $this->assertSame(1, DB::table('cash_handover')->count());
        // already-declared cash cannot be declared twice
        $this->declare('1500.0000')->assertStatus(422);
        $this->declare('1000.0000')->assertCreated();
        // another waiter holds nothing
        $this->declare('1.0000', $this->waiter2Token)->assertStatus(422);
    }

    public function test_handover_is_refused_when_holding_is_denied_and_nothing_is_in_hand_but_clears_leftover_cash(): void
    {
        $this->collectCash('2000.0000');
        $this->setRuleFlush('waiter_cash_holding', 'false');
        // cash already in hand may (must) still be handed over
        $h = $this->declare('2000.0000')->assertCreated()->json('id');
        $this->postJson("/api/v1/cash-handovers/{$h}/receive", ['countedAmount' => '2000.0000'], $this->auth($this->cashierToken))->assertOk();
        // now nothing is in hand and holding is off
        $this->declare('1.0000')->assertStatus(403)->assertJsonPath('code', 'cash_holding_not_allowed');
    }

    public function test_limit_forces_a_handover_before_more_cash_can_be_collected(): void
    {
        $this->setRuleFlush('waiter_cash_in_hand_limit', '10000');
        $this->collectCash('9000.0000');
        $o = $this->billedOrder('5000.0000');
        $this->collect($o, ['tenderType' => 'CASH', 'amount' => '2000.0000'])->assertStatus(409)->assertJsonPath('code', 'cash_limit_exceeded');
        $pos = $this->getJson("/api/v1/staff/{$this->waiter->id}/cash-in-hand", $this->auth($this->waiterToken, null))->assertOk();
        $this->assertSame('10000.0000', $pos->json('limit'));
        $this->assertFalse($pos->json('handoverRequired'));
        $h = $this->declare('9000.0000')->assertCreated()->json('id');
        $this->postJson("/api/v1/cash-handovers/{$h}/receive", ['countedAmount' => '9000.0000'], $this->auth($this->cashierToken))->assertOk();
        $this->collect($o, ['tenderType' => 'CASH', 'amount' => '2000.0000'])->assertCreated();
    }

    public function test_handover_list_is_scoped(): void
    {
        $this->collectCash('2000.0000');
        $h = $this->declare('2000.0000')->assertCreated()->json('id');
        $mine = $this->getJson('/api/v1/cash-handovers', $this->auth($this->waiterToken, null))->assertOk();
        $this->assertSame([$h], array_column($mine->json('items'), 'id'));
        $this->assertSame([], $this->getJson('/api/v1/cash-handovers', $this->auth($this->waiter2Token, null))->assertOk()->json('items'));
        $all = $this->getJson("/api/v1/cash-handovers?facilityId={$this->facility}&status=PENDING_RECEIPT", $this->auth($this->cashierToken, null))->assertOk();
        $this->assertSame([$h], array_column($all->json('items'), 'id'));
        $this->getJson("/api/v1/cash-handovers?facilityId={$this->facility}", $this->auth($this->waiter2Token, null))->assertStatus(403);
        $this->getJson("/api/v1/staff/{$this->waiter->id}/cash-in-hand", $this->auth($this->waiter2Token, null))->assertStatus(403);
    }
}
