<?php

namespace Tests\Feature;

use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\TestData;
use Tests\Support\Workers;

/** Real concurrency: separate PHP processes, separate MySQL connections, released at the same instant. */
class ConcurrencyTest extends ConcurrentTestCase
{
    public function test_concurrent_audit_writers_keep_a_single_valid_chain(): void
    {
        $t = TestData::tenant();
        $results = Concurrent::run(6, Workers::class, 'auditAppend', [$t['org'], $t['site'], 8]);

        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }
        $this->assertSame(48, DB::table('audit_log')->count());
        $v = Audit::verifyChain();
        $this->assertTrue($v->valid, (string) $v->reason);
        $this->assertSame(48, $v->rowsChecked);
    }

    public function test_concurrent_duplicate_idempotent_requests_apply_once(): void
    {
        $t = TestData::tenant();
        TestData::staff($t, 'racer');
        $token = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PASSWORD', 'identifier' => 'racer', 'secret' => TestData::PASSWORD])->json('accessToken');

        $results = Concurrent::run(6, Workers::class, 'idempotentPost', [$token, 'race-key-1', 'RaceOrg']);

        $ids = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            [$status, , $id] = $r['result'];
            $this->assertSame(201, $status);
            $ids[] = $id;
        }
        $this->assertCount(1, array_unique($ids), 'every caller must see the same original response');
        $this->assertSame(1, DB::table('organization')->where('name', 'RaceOrg')->count(), 'effect applied exactly once');
        $this->assertSame(1, DB::table('idempotency_record')->count());
    }
}
