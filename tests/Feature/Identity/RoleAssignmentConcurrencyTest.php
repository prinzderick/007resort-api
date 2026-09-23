<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Role;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\RoleWorkers;

/** Concurrent identical grants must converge on ONE active assignment (DB unique active_key), never duplicates or 500s. */
class RoleAssignmentConcurrencyTest extends ConcurrentTestCase
{
    public function test_parallel_identical_grants_create_exactly_one_assignment(): void
    {
        Artisan::call('r007:demo-seed');
        $mgr = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'manager1', 'secret' => '1234'])->json('accessToken');

        $results = Concurrent::run(6, RoleWorkers::class, 'grant', [$mgr, DemoIds::staff('cashier1'), Role::publicIdFor('STOREKEEPER'), DemoIds::facility('MAIN_STORE')]);

        $statuses = [];
        $bodies = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $statuses[] = $r['result'][0];
            $bodies[] = $r['result'][1];
        }
        $this->assertEmpty(array_diff($statuses, [200, 201]), 'no 4xx/5xx under contention: '.json_encode([$statuses, $bodies]));
        $roleId = Role::query()->where('code', 'STOREKEEPER')->value('id');
        $this->assertSame(1, DB::table('role_assignment')->where('staff_id', Ids::toBinary(DemoIds::staff('cashier1')))->where('role_id', $roleId)->where('is_active', 1)->count());
        $this->assertLessThanOrEqual(1, count(array_filter($statuses, fn ($s) => $s === 201)), 'at most one creator');
    }
}
