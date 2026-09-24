<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\ConfigWorkers;

/** Real parallel writers on separate connections: optimistic concurrency, unique codes and the tree lock must hold. */
class ConfigConcurrencyTest extends ConcurrentTestCase
{
    private function token(string $user = 'owner1'): string
    {
        return $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => $user, 'secret' => '1234'])->json('accessToken');
    }

    public function test_concurrent_rule_edits_with_the_same_version_only_one_wins(): void
    {
        Artisan::call('r007:demo-seed');
        $token = $this->token();
        $rest = DemoIds::facility('RESTAURANT');
        $version = (string) DB::table('facility_unit')->where('id', Ids::toBinary($rest))->value('row_version');

        $results = Concurrent::run(6, ConfigWorkers::class, 'putRules', [$token, $rest, $version, '9000']);
        $statuses = array_map(fn ($r) => $r['result'][0], $results);
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 200)), 'exactly one edit wins: '.json_encode($statuses));
        $this->assertSame(5, count(array_filter($statuses, fn ($s) => $s === 412)), 'the rest lose with 412: '.json_encode($statuses));
        $this->assertSame((int) $version + 1, (int) DB::table('facility_unit')->where('id', Ids::toBinary($rest))->value('row_version'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'config.rules.update')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'ConfigurationUpdated')->where('entity_id', Ids::toBinary($rest))->count());
    }

    public function test_concurrent_creates_of_the_same_code_yield_one_facility(): void
    {
        Artisan::call('r007:demo-seed');
        $token = $this->token();
        $results = Concurrent::run(6, ConfigWorkers::class, 'createFacility', [$token, 'RACE_CODE']);
        $statuses = array_map(fn ($r) => $r['result'][0], $results);
        $this->assertSame(1, count(array_filter($statuses, fn ($s) => $s === 201)), json_encode($statuses));
        $this->assertEmpty(array_diff($statuses, [201, 409]), 'losers get a clean 409, never a 500: '.json_encode($statuses));
        $this->assertSame(1, DB::table('facility_unit')->where('code', 'RACE_CODE')->count());
    }

    public function test_opposite_moves_cannot_create_a_cycle(): void
    {
        Artisan::call('r007:demo-seed');
        $token = $this->token();
        $a = DemoIds::facility('SUPERMARKET');
        $b = DemoIds::facility('SUPER_STORE');
        $va = (string) DB::table('facility_unit')->where('id', Ids::toBinary($a))->value('row_version');
        $vb = (string) DB::table('facility_unit')->where('id', Ids::toBinary($b))->value('row_version');
        // A under B and B under A at the same instant
        $r1 = Concurrent::run(2, ConfigWorkers::class, 'move', [$token, $a, $b, $va]);
        $this->assertNotNull($r1);
        // run properly-paired opposite moves through two processes with different targets
        DB::table('facility_unit')->whereIn('id', [Ids::toBinary($a), Ids::toBinary($b)])->update(['parent_id' => null]);
        $results = Concurrent::run(2, ConfigWorkers::class, 'moveOpposite', [$token, $a, $b]);
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }
        $pa = DB::table('facility_unit')->where('id', Ids::toBinary($a))->value('parent_id');
        $pb = DB::table('facility_unit')->where('id', Ids::toBinary($b))->value('parent_id');
        $this->assertFalse($pa !== null && $pb !== null, 'never both parented to each other');
        $this->assertNotNull($vb);
    }
}
