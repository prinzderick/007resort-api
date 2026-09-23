<?php

namespace Tests\Feature;

use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

/** V0001 ported to Laravel migrations: seeds present, CHECK constraints enforced by MySQL 8.4. */
class SchemaTest extends TestCase
{
    public function test_seed_catalogs_are_present(): void
    {
        $this->assertSame(22, DB::table('capability_type')->count());
        $this->assertSame(11, DB::table('role')->count());
        // V0001 seeds 42; later module migrations add more, so assert the floor.
        $this->assertGreaterThanOrEqual(42, DB::table('permission')->count());
        $owner = DB::table('role')->where('code', 'OWNER')->value('id');
        $this->assertGreaterThanOrEqual(42, DB::table('role_permission')->where('role_id', $owner)->count());
        $this->assertGreaterThan(80, DB::table('role_permission')->count());
    }

    public function test_sync_tables_exist(): void
    {
        foreach (['outbox_event', 'inbox_event', 'site_health', 'audit_log', 'idempotency_record', 'security_event', 'failed_jobs'] as $t) {
            $this->assertTrue(\Schema::hasTable($t), $t);
        }
    }

    public function test_check_constraints_are_enforced(): void
    {
        $t = TestData::tenant();
        $staff = TestData::staff($t, 'chk');
        $account = DB::table('user_account')->where('staff_id', Ids::toBinary($staff->id))->value('id');

        $this->assertViolates(fn () => DB::table('credential')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'user_account_id' => $account, 'credential_type' => 'BOGUS', 'credential_hash' => 'x',
        ]));
        $this->assertViolates(fn () => DB::table('device')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($t['org']), 'site_id' => Ids::toBinary($t['site']),
            'device_type' => 'TOASTER', 'name' => 'x',
        ]));
        $this->assertViolates(fn () => DB::table('role_assignment')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'staff_id' => Ids::toBinary($staff->id), 'role_id' => 1, 'scope_level' => 'GALAXY',
            'organization_id' => Ids::toBinary($t['org']),
        ]));
        $this->assertViolates(fn () => DB::table('security_event')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'event_type' => 'X', 'severity' => 'MEH',
        ]));
        $this->assertViolates(fn () => DB::table('outbox_event')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'event_type' => 'X', 'entity_type' => 'X', 'entity_id' => Ids::toBinary(Ids::uuid7()),
            'entity_version' => 1, 'organization_id' => Ids::toBinary($t['org']), 'payload' => '{}', 'sync_status' => 'NOPE',
        ]));
        $this->assertViolates(fn () => DB::table('site_health')->insert(['site_id' => Ids::toBinary($t['site']), 'status' => 'MAYBE']));
    }

    public function test_foreign_keys_and_uniques_are_enforced(): void
    {
        $this->assertViolates(fn () => DB::table('site')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary(Ids::uuid7()), 'name' => 'orphan',
        ]));
        $t = TestData::tenant();
        TestData::staff($t, 'dupe');
        $this->assertViolates(fn () => TestData::staff($t, 'dupe'));
    }

    private function assertViolates(callable $fn): void
    {
        // Savepoint so the surrounding test transaction survives the expected failure.
        DB::beginTransaction();
        try {
            $fn();
            DB::rollBack();
            $this->fail('Expected a constraint violation.');
        } catch (QueryException $e) {
            DB::rollBack();
            $this->assertMatchesRegularExpression('/(3819|1452|1062)/', (string) $e->getCode().$e->getMessage());
        }
    }
}
