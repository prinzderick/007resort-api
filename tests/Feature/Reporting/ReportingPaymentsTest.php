<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Support\ReportingViews;
use App\Domain\Reporting\Support\SourceCatalog;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ConcurrentTestCase;
use Tests\Support\MembersFixtures;
use Tests\Support\TestData;

/**
 * Reporting's payments-backed paths. The Payments module lands separately, so when its tables are absent this test creates minimal
 * stand-ins carrying the SAME column names as the Payments migrations (payment, refund, reversal, cash_session, cash_movement) and
 * drops them afterwards; once Payments is merged the real tables are used untouched. (DDL commits implicitly, hence a
 * non-transactional test case.)
 */
class ReportingPaymentsTest extends ConcurrentTestCase
{
    use MembersFixtures;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stub('payment', 'CREATE TABLE payment (id BINARY(16) PRIMARY KEY, organization_id BINARY(16) NOT NULL, site_id BINARY(16) NOT NULL, facility_unit_id BINARY(16) NOT NULL,
            group_id BINARY(16) NOT NULL, tender_type VARCHAR(16) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'CAPTURED\', amount DECIMAL(19,4) NOT NULL,
            refunded_amount DECIMAL(19,4) NOT NULL DEFAULT 0, cash_session_id BINARY(16) NULL, captured_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))');
        $this->stub('refund', 'CREATE TABLE refund (id BINARY(16) PRIMARY KEY, organization_id BINARY(16) NOT NULL, site_id BINARY(16) NOT NULL, payment_id BINARY(16) NOT NULL,
            amount DECIMAL(19,4) NOT NULL, reason VARCHAR(255) NOT NULL, tender_type VARCHAR(16) NOT NULL, cash_session_id BINARY(16) NULL, requested_by_staff_id BINARY(16) NOT NULL,
            executed_by_staff_id BINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))');
        $this->stub('reversal', 'CREATE TABLE reversal (id BINARY(16) PRIMARY KEY, organization_id BINARY(16) NOT NULL, site_id BINARY(16) NOT NULL, payment_id BINARY(16) NOT NULL,
            amount DECIMAL(19,4) NOT NULL, reason VARCHAR(255) NOT NULL, tender_type VARCHAR(16) NOT NULL, cash_session_id BINARY(16) NULL, requested_by_staff_id BINARY(16) NOT NULL,
            executed_by_staff_id BINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))');
        $this->stub('cash_session', 'CREATE TABLE cash_session (id BINARY(16) PRIMARY KEY, organization_id BINARY(16) NOT NULL, site_id BINARY(16) NOT NULL, facility_unit_id BINARY(16) NOT NULL,
            staff_id BINARY(16) NOT NULL, status VARCHAR(8) NOT NULL DEFAULT \'OPEN\', opening_float DECIMAL(19,4) NOT NULL, expected_cash DECIMAL(19,4) NULL, counted_cash DECIMAL(19,4) NULL,
            variance DECIMAL(19,4) NULL, opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), closed_at DATETIME(6) NULL)');
        $this->stub('cash_movement', 'CREATE TABLE cash_movement (id BINARY(16) PRIMARY KEY, cash_session_id BINARY(16) NOT NULL, kind VARCHAR(10) NOT NULL, amount DECIMAL(19,4) NOT NULL,
            reason VARCHAR(255) NOT NULL, staff_id BINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))');
        SourceCatalog::flush();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $t) {
            DB::statement("DROP TABLE IF EXISTS `{$t}`");
        }
        SourceCatalog::flush();
        ReportingViews::install();
        parent::tearDown();
    }

    /** One app instance serves several requests: drop cached guard users like a fresh request would (same as Tests\TestCase). */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function stub(string $table, string $ddl): void
    {
        if (! Schema::hasTable($table)) {
            DB::statement($ddl);
            $this->created[] = $table;
        }
    }

    private function id(): array
    {
        $u = Ids::uuid7();

        return [$u, Ids::toBinary($u)];
    }

    public function test_payment_backed_reports(): void
    {
        $rest = TestData::facility($this->t, 'restaurant');
        [$cashier] = [TestData::staff($this->t, 'cashier')];
        TestData::assign($cashier, 'CASHIER');
        $mgr = TestData::staff($this->t, 'mgr');
        TestData::assign($mgr, 'MANAGER');
        $tok = $this->login('mgr');
        $org = Ids::toBinary($this->t['org']);
        $site = Ids::toBinary($this->t['site']);
        $fac = Ids::toBinary($rest->id);
        $cb = Ids::toBinary($cashier->id);

        [$sess, $sessBin] = $this->id();
        DB::table('cash_session')->insert(['id' => $sessBin, 'organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => $fac, 'staff_id' => $cb, 'status' => 'OPEN',
            'opening_float' => '5000.0000', 'opened_at' => '2026-09-22 07:00:00.000000']);
        $pay = function (string $tender, string $amount, ?string $session, string $status = 'CAPTURED', string $at = '2026-09-22 10:00:00.000000') use ($org, $site, $fac): string {
            [$u, $b] = $this->id();
            DB::table('payment')->insert(['id' => $b, 'organization_id' => $org, 'site_id' => $site, 'facility_unit_id' => $fac, 'group_id' => Ids::toBinary(Ids::uuid7()),
                'tender_type' => $tender, 'status' => $status, 'amount' => $amount, 'cash_session_id' => $session === null ? null : Ids::toBinary($session), 'captured_at' => $at, 'created_at' => $at]);

            return $u;
        };
        $cash1 = $pay('CASH', '10000.0000', $sess);
        $pay('CASH', '2500.0000', $sess);
        $pay('CARD', '8000.0000', $sess);
        $pay('CASH', '999.0000', $sess, 'FAILED'); // not captured: excluded
        $pay('POS_TERMINAL', '4000.0000', null, 'CAPTURED', '2026-09-23 10:00:00.000000'); // next day
        DB::table('refund')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $org, 'site_id' => $site, 'payment_id' => Ids::toBinary($cash1), 'amount' => '1000.0000', 'reason' => 'x',
            'tender_type' => 'CASH', 'cash_session_id' => $sessBin, 'requested_by_staff_id' => $cb, 'executed_by_staff_id' => $cb, 'created_at' => '2026-09-22 11:00:00.000000']);
        DB::table('cash_movement')->insert([
            ['id' => Ids::toBinary(Ids::uuid7()), 'cash_session_id' => $sessBin, 'kind' => 'PAID_IN', 'amount' => '500.0000', 'reason' => 'float top-up', 'staff_id' => $cb],
            ['id' => Ids::toBinary(Ids::uuid7()), 'cash_session_id' => $sessBin, 'kind' => 'DROP', 'amount' => '3000.0000', 'reason' => 'safe drop', 'staff_id' => $cb],
        ]);

        $r = $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$rest->id}", ['Authorization' => 'Bearer '.$tok, 'Accept' => 'application/json'])->assertOk();
        $this->assertSame(['CARD' => '8000.0000', 'CASH' => '12500.0000'], collect($r->json('byTender'))->pluck('amount', 'tenderType')->all());
        $r->assertJsonPath('refunds', '1000.0000')->assertJsonPath('availability.payments', true);

        $rev = $this->getJson('/api/v1/reports/revenue?from=2026-09-22&to=2026-09-23', ['Authorization' => 'Bearer '.$tok, 'Accept' => 'application/json'])->assertOk();
        $this->assertSame(['CARD', 'CASH', 'POS_TERMINAL'], array_column($rev->json('byPaymentMethod'), 'tenderType'));

        // open session: expected = 5000 + 12500 - 1000 refund + 500 - 3000 = 14000
        $s = $this->getJson("/api/v1/reports/cashier-shift/{$sess}", ['Authorization' => 'Bearer '.$tok, 'Accept' => 'application/json'])->assertOk();
        $s->assertJsonPath('openingFloat', '5000.0000')->assertJsonPath('expectedCash', '14000.0000')->assertJsonPath('refunds', '1000.0000')->assertJsonPath('countedCash', null)
            ->assertJsonPath('cashSessionId', $sess)->assertJsonPath('staffName', 'Cashier Tester');
        $this->assertSame(['CARD' => '8000.0000', 'CASH' => '12500.0000'], collect($s->json('byTender'))->pluck('amount', 'tenderType')->all());
        $this->assertCount(3, $s->json('paymentIds'));

        // closed session reports the stored figures
        DB::table('cash_session')->where('id', $sessBin)->update(['status' => 'CLOSED', 'expected_cash' => '14000.0000', 'counted_cash' => '13950.0000', 'variance' => '-50.0000', 'closed_at' => '2026-09-22 18:00:00.000000']);
        $this->getJson("/api/v1/reports/cashier-shift/{$sess}", ['Authorization' => 'Bearer '.$tok, 'Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('countedCash', '13950.0000')->assertJsonPath('variance', '-50.0000')->assertJsonPath('status', 'CLOSED');

        // views over the payments schema
        $views = ReportingViews::install();
        $this->assertSame('created', $views['v_payments_by_tender_daily']);
        $this->assertSame('created', $views['v_cashier_shift_report']);
        $row = DB::table('v_payments_by_tender_daily')->where('tender_type', 'CASH')->first();
        $this->assertSame('12500.0000', $row->captured);
    }
}
