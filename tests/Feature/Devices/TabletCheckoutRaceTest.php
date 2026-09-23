<?php

namespace Tests\Feature\Devices;

use App\Support\Audit\Audit;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\DeviceWorkers;

/** Real concurrency: 8 processes race to check out the SAME tablet to different attendants — exactly one may win. */
class TabletCheckoutRaceTest extends ConcurrentTestCase
{
    public function test_exactly_one_active_checkout_per_device_under_a_real_race(): void
    {
        Artisan::call('r007:demo-seed');
        $login = fn (string $u) => $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => $u, 'secret' => '1234'])->json();
        $manager = $login('manager1'); // may check out for anyone
        $staffIds = array_map(fn ($u) => DemoIds::staff($u), ['wait1', 'wait2', 'bartender1', 'kitchen1', 'cashier1', 'cashier2', 'storekeeper1', 'supervisor1']);
        $facilities = [DemoIds::facility('RESTAURANT'), DemoIds::facility('POOL_BAR'), DemoIds::facility('POOL_BAR'), DemoIds::facility('MAIN_KITCHEN'), DemoIds::facility('RECEPTION'), DemoIds::facility('CAFE'), DemoIds::facility('MAIN_STORE'), DemoIds::facility('RESTAURANT')];

        $results = Concurrent::run(8, DeviceWorkers::class, 'checkout', [$manager['accessToken'], DemoIds::device('TABLET_WAITER_05'), $staffIds, $facilities]);

        $statuses = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $statuses[] = $r['result'][0];
        }
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 200)), 'exactly one winner: '.json_encode($results));
        $this->assertSame(7, count(array_filter($statuses, fn ($s) => $s === 409)), 'everyone else gets 409 concurrency_conflict, never a 500: '.json_encode($results));
        $this->assertSame(1, DB::table('tablet_checkout')->where('device_id', Ids::toBinary(DemoIds::device('TABLET_WAITER_05')))->whereNull('checked_in_at')->count());
        $this->assertSame(1, DB::table('tablet_checkout')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'device.checkout')->count(), 'losers leave no audit rows');
        $this->assertTrue(Audit::verifyChain()->valid);
    }
}
