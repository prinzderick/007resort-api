<?php

namespace Tests\Feature\Membership;

use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\MembersFixtures;
use Tests\Support\MemberWorkers;
use Tests\Support\TestData;

/** Real processes, real MySQL: a visit limit of N admits exactly N of M > N simultaneous scans. */
class VisitLimitRaceTest extends ConcurrentTestCase
{
    use MembersFixtures;

    public function test_limit_n_admits_exactly_n_concurrent_scans(): void
    {
        $this->bootTenant();
        $pool = TestData::facility($this->t, 'pool');
        $plan = $this->plan(['visit_limit' => 5, 'guest_allowance' => 0]);
        $m = $this->sell($plan->id);

        $results = Concurrent::run(14, MemberWorkers::class, 'scan', [$m->id, $pool->id, false]);

        $valid = 0;
        $reasons = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $r['result']['valid'] ? $valid++ : $reasons[] = $r['result']['reason'];
        }
        $this->assertSame(5, $valid, 'exactly the visit limit must be admitted');
        $this->assertSame(array_fill(0, 9, 'VISIT_LIMIT_REACHED'), $reasons);
        $this->assertSame(5, Membership::find($m->id)->visits_used);
        $this->assertSame(5, DB::table('membership_usage')->where('membership_id', Ids::toBinary($m->id))->count());
        $this->assertSame([1, 2, 3, 4, 5], DB::table('membership_usage')->orderBy('visit_number')->pluck('visit_number')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5, DB::table('outbox_event')->where('event_type', 'MembershipUsageRecorded')->count());
    }

    public function test_concurrent_retries_with_the_same_client_ref_admit_once(): void
    {
        $this->bootTenant();
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->sell($this->plan(['visit_limit' => 10])->id);

        $results = Concurrent::run(8, MemberWorkers::class, 'scan', [$m->id, $pool->id, true]);

        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $this->assertTrue($r['result']['valid']);
        }
        $this->assertSame(1, Membership::find($m->id)->visits_used);
        $this->assertSame(1, DB::table('membership_usage')->count());
    }

    public function test_unlimited_plan_admits_everyone(): void
    {
        $this->bootTenant();
        $pool = TestData::facility($this->t, 'pool');
        $m = $this->sell($this->plan(['visit_limit' => null])->id);
        $results = Concurrent::run(6, MemberWorkers::class, 'scan', [$m->id, $pool->id, false]);
        foreach ($results as $r) {
            $this->assertTrue($r['result']['valid'], (string) $r['error']);
        }
        $this->assertSame(6, Membership::find($m->id)->visits_used);
    }

    private function sell(string $planId): Membership
    {
        $staff = TestData::staff($this->t, 'seller');
        $m = app(MembershipService::class)->purchase($planId, ['name' => 'Racer', 'phone' => '0800'.random_int(1000000, 9999999)], null,
            [['tenderType' => 'CASH', 'amount' => '50000.0000']], null, $staff->id);
        $this->assertSame('ACTIVE', $m->status);

        return $m;
    }
}
