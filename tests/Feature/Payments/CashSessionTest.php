<?php

namespace Tests\Feature\Payments;

use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsWorld;
use Tests\Support\TestData;
use Tests\TestCase;

class CashSessionTest extends TestCase
{
    use PaymentsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function open(string $token, string $float = '5000.0000')
    {
        return $this->postJson('/api/v1/cash-sessions', ['facilityId' => $this->facility, 'openingFloat' => $float], $this->auth($token));
    }

    public function test_open_take_cash_close_with_shortage_records_expected_counted_and_variance(): void
    {
        $s = $this->open($this->cashierToken, '5000.0000')->assertCreated()->assertJsonPath('status', 'OPEN')->assertJsonPath('expectedCash', '5000.0000')->json('id');
        $order = $this->makeOrder('9000.0000');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $order, 'amount' => '9000.0000']], [['tenderType' => 'CASH', 'amount' => '9000.0000', 'tendered' => '10000.0000']]), $this->auth($this->cashierToken))->assertCreated();
        $o2 = $this->makeOrder('2000.0000');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $o2, 'amount' => '2000.0000']], [['tenderType' => 'POS_TERMINAL', 'amount' => '2000.0000', 'reference' => 'RRN-Z']]), $this->auth($this->cashierToken))->assertCreated();
        $this->postJson("/api/v1/cash-sessions/{$s}/movements", ['kind' => 'PAID_OUT', 'amount' => '500.0000', 'reason' => 'Bought ice'], $this->auth($this->cashierToken))->assertCreated();
        $this->postJson("/api/v1/cash-sessions/{$s}/movements", ['kind' => 'DROP', 'amount' => '3000.0000', 'reason' => 'Safe drop'], $this->auth($this->cashierToken))->assertCreated();

        // live view while open: 5000 float + 9000 cash sale - 500 paid out - 3000 drop = 10500 (POS is not drawer cash)
        $live = $this->getJson("/api/v1/cash-sessions/{$s}", $this->auth($this->cashierToken, null))->assertOk();
        $live->assertJsonPath('expectedCash', '10500.0000')->assertJsonPath('countedCash', null)->assertJsonPath('totals.nonCash.POS_TERMINAL', '2000.0000');

        $closed = $this->postJson("/api/v1/cash-sessions/{$s}/close", ['countedCash' => '10300.0000', 'note' => 'Short 200'], $this->auth($this->cashierToken))->assertOk();
        $closed->assertJsonPath('status', 'CLOSED')->assertJsonPath('expectedCash', '10500.0000')->assertJsonPath('countedCash', '10300.0000')->assertJsonPath('variance', '-200.0000')
            ->assertJsonPath('note', 'Short 200')->assertJsonPath('totals.cashSales', '9000.0000');
        $this->assertNotNull($closed->json('closedAt'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'cash_session.close')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'CashSessionClosed')->count());
        $this->assertSame(1, DB::table('security_event')->where('event_type', 'cash_session.variance')->count());

        // closed => frozen, no more cash, no second close, no more movements
        $o3 = $this->makeOrder('100.0000');
        $this->postJson('/api/v1/payments', $this->payBody([['orderId' => $o3, 'amount' => '100.0000']], [['tenderType' => 'CASH', 'amount' => '100.0000']], $s), $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'cash_session_required');
        $this->postJson("/api/v1/cash-sessions/{$s}/close", ['countedCash' => '1.0000'], $this->auth($this->cashierToken))->assertStatus(409)->assertJsonPath('code', 'cash_session_closed');
        $this->postJson("/api/v1/cash-sessions/{$s}/movements", ['kind' => 'PAID_IN', 'amount' => '1.0000', 'reason' => 'late'], $this->auth($this->cashierToken))->assertStatus(409);
    }

    public function test_only_one_open_session_per_cashier_and_per_device_enforced_by_the_database(): void
    {
        $first = $this->open($this->cashierToken)->assertCreated()->json('id');
        $this->open($this->cashierToken)->assertStatus(409)->assertJsonPath('code', 'cash_session_already_open')->assertJsonPath('cashSessionId', $first);
        $this->postJson("/api/v1/cash-sessions/{$first}/close", ['countedCash' => '5000.0000'], $this->auth($this->cashierToken))->assertOk();
        $this->open($this->cashierToken)->assertCreated(); // after closing, a new one is fine

        // raw SQL cannot sneak a second OPEN row in either
        $this->expectException(QueryException::class);
        $this->openSession($this->cashier);
    }

    public function test_only_the_owner_or_a_supervisor_can_close_a_session(): void
    {
        $s = $this->open($this->cashierToken)->json('id');
        $other = TestData::staff($this->t, 'cashier9');
        TestData::assign($other, 'CASHIER', 'SITE');
        $this->postJson("/api/v1/cash-sessions/{$s}/close", ['countedCash' => '5000.0000'], $this->auth($this->loginToken('cashier9')))->assertForbidden()->assertJsonPath('permission', 'cash_session.close_any');
        $this->postJson("/api/v1/cash-sessions/{$s}/close", ['countedCash' => '5000.0000'], $this->auth($this->supervisorToken))->assertOk()->assertJsonPath('variance', '0.0000');
    }

    public function test_validation_and_listing(): void
    {
        $this->open($this->cashierToken, '-1')->assertStatus(422);
        $this->open($this->cashierToken, '10.12345')->assertStatus(422);
        $s = $this->open($this->cashierToken)->json('id');
        $this->postJson("/api/v1/cash-sessions/{$s}/close", ['countedCash' => 'abc'], $this->auth($this->cashierToken))->assertStatus(422);
        $this->getJson('/api/v1/cash-sessions', $this->auth($this->cashierToken, null))->assertOk()->assertJsonPath('items.0.id', $s);
        $this->getJson("/api/v1/cash-sessions?filter[facilityId]={$this->facility}&filter[status]=OPEN", $this->auth($this->supervisorToken, null))->assertOk()->assertJsonPath('items.0.id', $s);
        $this->getJson('/api/v1/cash-sessions/'.Ids::uuid7(), $this->auth($this->supervisorToken, null))->assertNotFound();
    }
}
