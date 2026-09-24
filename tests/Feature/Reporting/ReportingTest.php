<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Support\Freshness;
use App\Domain\Reporting\Support\ReportingViews;
use App\Domain\Reporting\Support\SourceCatalog;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\MembersFixtures;
use Tests\Support\TestData;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use MembersFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        SourceCatalog::flush();
        $this->bootTenant();
    }

    private function h(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** Insert a settled order directly (Orders owns writes; Reporting only reads). FKs off for brevity. */
    private function order(string $facilityId, string $total, string $settledAtUtc, array $over = []): string
    {
        $id = Ids::uuid7();
        $staff = TestData::staff($this->t, 's'.random_int(100000, 999999));
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order')->insert($over + [
            'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($facilityId), 'order_number' => 'T-'.substr($id, -8), 'status' => 'SETTLED', 'subtotal' => $total, 'discount_total' => '0', 'tax_total' => '0',
            'total' => $total, 'amount_paid' => $total, 'created_by' => Ids::toBinary($staff->id), 'settled_at' => $settledAtUtc, 'created_at' => $settledAtUtc,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return $id;
    }

    public function test_facility_daily_summary_uses_site_local_date_and_descendants(): void
    {
        $rest = TestData::facility($this->t, 'restaurant');
        $counter = TestData::facility($this->t, 'counter', $rest->id);
        $bar = TestData::facility($this->t, 'bar');
        [, $tok] = $this->actor('mgr');

        // 2026-09-22 local (Lagos, UTC+1) = [2026-09-21T23:00Z, 2026-09-22T23:00Z)
        $this->order($rest->id, '1000.0000', '2026-09-21 23:00:00.000000'); // first instant of the day: IN
        $this->order($counter->id, '2500.5000', '2026-09-22 12:00:00.000000', ['discount_total' => '100.0000', 'subtotal' => '2600.5000', 'tax_total' => '0']);
        $this->order($rest->id, '700.0000', '2026-09-22 22:59:59.000000'); // last second: IN
        $this->order($rest->id, '999.0000', '2026-09-22 23:00:00.000000'); // next local day: OUT
        $this->order($rest->id, '888.0000', '2026-09-21 22:59:59.000000'); // previous local day: OUT
        $this->order($bar->id, '5000.0000', '2026-09-22 12:00:00.000000'); // other facility: OUT
        $this->order($rest->id, '300.0000', '2026-09-22 12:00:00.000000', ['status' => 'VOIDED']); // not settled: OUT

        $r = $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$rest->id}", $this->h($tok))->assertOk();
        $r->assertJsonPath('orders', 3)->assertJsonPath('grossSales', '4300.5000')->assertJsonPath('discounts', '100.0000')->assertJsonPath('total', '4200.5000')
            ->assertJsonPath('netSales', '4200.5000')->assertJsonPath('currency', 'NGN')->assertJsonPath('facilityId', $rest->id)->assertJsonPath('date', '2026-09-22');
        $r->assertJsonPath('availability.orders', true);
        $this->assertSame([], $r->json('byTender'));
        $this->assertArrayHasKey('freshness', $r->json());
        $this->assertSame('local', $r->json('freshness.sourceNode'));
        $this->assertFalse($r->json('freshness.stale'));

        $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$bar->id}", $this->h($tok))->assertOk()->assertJsonPath('orders', 1)->assertJsonPath('total', '5000.0000');
    }

    public function test_daily_summary_top_products_and_voids_from_orders_schema(): void
    {
        $rest = TestData::facility($this->t, 'restaurant');
        [, $tok] = $this->actor('mgr');
        $o = $this->order($rest->id, '3000.0000', '2026-09-22 12:00:00.000000');
        $p1 = Ids::uuid7();
        $p2 = Ids::uuid7();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([[$p1, 'Jollof Rice', 4, '2000.0000', 1], [$p2, 'Water', 2, '1000.0000', 2]] as [$pid, $name, $q, $tot, $n]) {
            DB::table('order_line')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'order_id' => Ids::toBinary($o), 'product_id' => Ids::toBinary($pid), 'line_no' => $n, 'product_name' => $name,
                'sku' => 'S'.$n, 'quantity' => $q, 'unit_price' => '500.0000', 'line_total' => $tot, 'gross_amount' => $tot, 'status' => 'LOCKED']);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $r = $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$rest->id}", $this->h($tok))->assertOk();
        $this->assertSame(['Jollof Rice', 'Water'], array_column($r->json('topProducts'), 'name'));
        $this->assertSame(4, $r->json('topProducts.0.quantity'));
        $this->assertSame('2000.0000', $r->json('topProducts.0.revenue'));
        $this->assertSame(['count' => 0, 'amount' => '0.0000'], $r->json('voids'));
    }

    public function test_revenue_by_facility_operating_point_and_absent_payments_degrade(): void
    {
        $rest = TestData::facility($this->t, 'restaurant');
        $bar = TestData::facility($this->t, 'bar');
        [, $tok] = $this->actor('mgr');
        $dev = Ids::uuid7();
        DB::table('device')->insert(['id' => Ids::toBinary($dev), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']), 'device_type' => 'POS', 'name' => 'POS-7']);
        DB::table('device_binding')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'device_id' => Ids::toBinary($dev), 'facility_unit_id' => Ids::toBinary($rest->id), 'operating_point_label' => 'Terrace till', 'bound_at' => '2026-01-01 00:00:00.000000']);
        $this->order($rest->id, '1000.0000', '2026-09-22 10:00:00.000000', ['device_id' => Ids::toBinary($dev)]);
        $this->order($rest->id, '2000.0000', '2026-09-22 11:00:00.000000');
        $this->order($bar->id, '4000.0000', '2026-09-23 11:00:00.000000');

        $r = $this->getJson('/api/v1/reports/revenue?from=2026-09-22&to=2026-09-23', $this->h($tok))->assertOk();
        $r->assertJsonPath('revenue', '7000.0000')->assertJsonPath('orders', 3);
        $this->assertSame(['bar' => '4000.0000', 'restaurant' => '3000.0000'], collect($r->json('byFacility'))->pluck('revenue', 'code')->all());
        $this->assertSame(['UNASSIGNED' => '6000.0000', 'Terrace till' => '1000.0000'], collect($r->json('byOperatingPoint'))->pluck('revenue', 'operatingPoint')->sortKeys()->reverse()->all());
        $this->assertArrayHasKey('freshness', $r->json());
        if (! SourceCatalog::has('payment')) {
            $this->assertSame([], $r->json('byPaymentMethod'));
            $this->assertFalse($r->json('availability.payments'));
        }
        $this->getJson("/api/v1/reports/revenue?from=2026-09-22&to=2026-09-23&facilityId={$bar->id}", $this->h($tok))->assertOk()->assertJsonPath('revenue', '4000.0000');
        $this->getJson('/api/v1/reports/revenue?from=2026-09-23&to=2026-09-22', $this->h($tok))->assertStatus(422);
        $this->getJson('/api/v1/reports/revenue?from=2024-01-01&to=2026-09-22', $this->h($tok))->assertStatus(422);
    }

    public function test_cashier_shift_report_from_orders_shift_without_payments(): void
    {
        $rest = TestData::facility($this->t, 'restaurant');
        [$cashier, $tok] = $this->actor('mgr');
        $shift = Ids::uuid7();
        DB::table('shift')->insert(['id' => Ids::toBinary($shift), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($rest->id), 'staff_id' => Ids::toBinary($cashier->id), 'status' => 'OPEN', 'opened_at' => '2026-09-22 07:00:00.000000']);
        $r = $this->getJson("/api/v1/reports/cashier-shift/{$shift}", $this->h($tok))->assertOk();
        $r->assertJsonPath('shiftId', $shift)->assertJsonPath('staffName', 'Mgr Tester')->assertJsonPath('facilityId', $rest->id)->assertJsonPath('status', 'OPEN')->assertJsonPath('openingFloat', '0.0000');
        $this->assertArrayHasKey('freshness', $r->json());
        $this->getJson('/api/v1/reports/cashier-shift/'.Ids::uuid7(), $this->h($tok))->assertStatus(404)->assertJsonPath('code', 'shift_not_found');
    }

    public function test_attendance_and_membership_summaries(): void
    {
        [$mgrStaff, $tok] = $this->actor('mgr');
        $alice = TestData::staff($this->t, 'alice');
        foreach ([['2026-09-21', 'CLOSED', 480, 'BIOMETRIC'], ['2026-09-22', 'CLOSED', 540, 'MANUAL_CORRECTION'], ['2026-09-23', 'OPEN', null, 'BIOMETRIC']] as [$d, $st, $m, $src]) {
            DB::table('attendance_day')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
                'staff_id' => Ids::toBinary($alice->id), 'work_date' => $d, 'status' => $st, 'minutes_worked' => $m, 'source' => $src, 'punch_count' => 2]);
        }
        $r = $this->getJson('/api/v1/reports/attendance-summary?from=2026-09-21&to=2026-09-23', $this->h($tok))->assertOk();
        $row = $r->json('staff.0');
        $this->assertSame([3, 2, 1, 1020, 510], [$row['daysRecorded'], $row['closedDays'], $row['openDays'], $row['totalMinutes'], $row['averageMinutesPerClosedDay']]);
        $this->assertSame(1, $row['correctedDays']);
        $this->assertSame(1, $r->json('totals.staffCount'));
        $this->assertArrayHasKey('freshness', $r->json());
        $this->getJson('/api/v1/reports/attendance-summary?from=2026-09-21&to=2026-09-23&staffId='.$mgrStaff->id, $this->h($tok))->assertOk()->assertJsonCount(0, 'staff');

        $plan = $this->plan();
        $this->postJson('/api/v1/memberships', ['planId' => $plan->id, 'customer' => ['name' => 'M'], 'tenders' => [['tenderType' => 'CASH', 'amount' => '50000']]], $this->idem($tok))->assertCreated();
        $s = $this->getJson('/api/v1/reports/membership-summary', $this->h($tok))->assertOk();
        $this->assertSame(['ACTIVE' => 1], $s->json('byStatus'));
        $this->assertSame(1, $s->json('byPlan.0.active'));
        $this->assertArrayHasKey('freshness', $s->json());
    }

    public function test_report_permissions_are_scoped(): void
    {
        $spa = TestData::facility($this->t, 'spa');
        $gym = TestData::facility($this->t, 'gym');
        [, $spaSup] = $this->actor('spasup', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $spa->id);
        [, $waiter] = $this->actor('waiter', 'WAIT_STAFF');
        [, $mgr] = $this->actor('mgr');

        $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$spa->id}", $this->h($spaSup))->assertOk();
        $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$gym->id}", $this->h($spaSup))->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$spa->id}", $this->h($waiter))->assertStatus(403)->assertJsonPath('permission', 'report.view');
        $this->getJson('/api/v1/reports/revenue?from=2026-09-22&to=2026-09-22', $this->h($spaSup))->assertStatus(403)->assertJsonPath('permission', 'report.view.all');
        $this->getJson('/api/v1/reports/revenue?from=2026-09-22&to=2026-09-22', $this->h($mgr))->assertOk();
        $this->getJson('/api/v1/reports/attendance-summary?from=2026-09-22&to=2026-09-22', $this->h($waiter))->assertStatus(403);
        $this->getJson('/api/v1/reports/membership-summary', $this->h($waiter))->assertStatus(403);
        $this->getJson('/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId='.Ids::uuid7(), $this->h($mgr))->assertStatus(404);
        $this->getJson("/api/v1/reports/facility-daily-summary?facilityId={$spa->id}", $this->h($mgr))->assertStatus(422);
        $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$spa->id}", ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_freshness_local_and_cloud_rules(): void
    {
        $now = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');

        config(['node.node' => 'local']);
        $f = Freshness::forSite($this->t['site'], $now);
        $this->assertFalse($f['stale']);
        $this->assertSame('local', $f['sourceNode']);
        $this->assertNull($f['lastSyncAt']);
        $this->assertSame('2026-09-23T12:00:00.000Z', $f['generatedAt']);

        config(['node.node' => 'cloud', 'reporting.stale_after_seconds' => 300]);
        $f = Freshness::forSite($this->t['site'], $now);
        $this->assertTrue($f['stale']);
        $this->assertSame('never_synced', $f['staleReason']);
        $this->assertSame('cloud', $f['sourceNode']);

        $health = fn (string $status, ?string $lastSync) => DB::table('site_health')->updateOrInsert(['site_id' => Ids::toBinary($this->t['site'])], ['status' => $status, 'last_sync_at' => $lastSync]);
        $health('ONLINE', '2026-09-23 11:58:00.000000');
        $f = Freshness::forSite($this->t['site'], $now);
        $this->assertFalse($f['stale']);
        $this->assertSame(120, $f['ageSeconds']);
        $this->assertSame('2026-09-23T11:58:00.000Z', $f['lastSyncAt']);

        $health('ONLINE', '2026-09-23 11:00:00.000000');
        $f = Freshness::forSite($this->t['site'], $now);
        $this->assertTrue($f['stale']);
        $this->assertSame('sync_lagging', $f['staleReason']);
        $this->assertSame(3600, $f['ageSeconds']);

        $health('OFFLINE', '2026-09-23 11:59:30.000000');
        $this->assertSame('site_offline', Freshness::forSite($this->t['site'], $now)['staleReason']);
    }

    public function test_cloud_node_report_response_is_flagged_stale_when_unsynced(): void
    {
        $spa = TestData::facility($this->t, 'spa');
        [, $tok] = $this->actor('mgr');
        config(['node.node' => 'cloud']);
        $r = $this->getJson("/api/v1/reports/facility-daily-summary?date=2026-09-22&facilityId={$spa->id}", $this->h($tok))->assertOk();
        $this->assertTrue($r->json('freshness.stale'));
        $this->assertSame('cloud', $r->json('freshness.sourceNode'));
        $this->assertSame('never_synced', $r->json('freshness.staleReason'));
    }

    public function test_local_freshness_reports_last_synced_outbox_event(): void
    {
        config(['node.node' => 'local']);
        DB::table('outbox_event')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'event_type' => 'X', 'entity_type' => 'X', 'entity_id' => Ids::toBinary(Ids::uuid7()), 'entity_version' => 1,
            'organization_id' => Ids::toBinary($this->t['org']), 'payload' => '{}', 'sync_status' => 'SYNCED', 'last_attempt_at' => '2026-09-23 11:30:00.000000']);
        $f = Freshness::forSite($this->t['site'], CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
        $this->assertSame('2026-09-23T11:30:00.000Z', $f['lastSyncAt']);
        $this->assertFalse($f['stale']);
    }

    public function test_views_are_created_only_for_available_sources_and_are_readable(): void
    {
        $res = ReportingViews::install();
        $this->assertSame('created', $res['v_attendance_day_summary']);
        $this->assertSame('created', $res['v_membership_status_summary']);
        $this->assertSame('created', $res['v_facility_daily_summary']);
        $rest = TestData::facility($this->t, 'restaurant');
        $this->order($rest->id, '1500.0000', '2026-09-22 23:30:00.000000'); // 00:30 Lagos on the 23rd
        $row = DB::table('v_facility_daily_summary')->where('facility_unit_id', Ids::toBinary($rest->id))->first();
        $this->assertSame('2026-09-23', $row->business_date);
        $this->assertSame('1500.0000', $row->total);
        $this->assertSame(1, (int) $row->orders);
        // read-only by construction: grouped views are not updatable
        $this->expectException(QueryException::class);
        DB::table('v_facility_daily_summary')->delete();
    }
}
