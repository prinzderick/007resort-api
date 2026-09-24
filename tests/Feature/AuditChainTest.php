<?php

namespace Tests\Feature;

use App\Support\Audit\Audit;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
        DB::table('audit_log')->delete();
        TestData::resetAuditChain();
    }

    private function write(string $action, ?array $old = null, ?array $new = null): string
    {
        return Audit::record($action, 'Thing', Ids::uuid7(), $old, $new, $this->t['org'], $this->t['site']);
    }

    public function test_chain_links_and_verifies(): void
    {
        $this->write('a', null, ['n' => 1]);
        $this->write('b', ['n' => 1], ['n' => 2, 'amount' => '10.5000', 'nested' => ['z' => 1, 'a' => [1, 2]]]);
        $this->write('c');

        $rows = DB::table('audit_log')->orderBy('seq')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(Audit::GENESIS_HASH, $rows[0]->prev_hash);
        $this->assertSame($rows[0]->row_hash, $rows[1]->prev_hash);
        $this->assertSame($rows[1]->row_hash, $rows[2]->prev_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rows[2]->row_hash);

        $v = Audit::verifyChain();
        $this->assertTrue($v->valid, (string) $v->reason);
        $this->assertSame(3, $v->rowsChecked);
        $this->assertSame($rows[2]->row_hash, $v->tailHash);
    }

    public function test_tampering_with_a_row_is_detected(): void
    {
        $this->write('a', null, ['amount' => '100.0000']);
        $id = $this->write('b', null, ['amount' => '200.0000']);
        $this->write('c');
        $this->assertTrue(Audit::verifyChain()->valid);

        DB::table('audit_log')->where('id', Ids::toBinary($id))->update(['new_value' => json_encode(['amount' => '1.0000'])]);

        $v = Audit::verifyChain();
        $this->assertFalse($v->valid);
        $this->assertStringContainsString('row_hash', (string) $v->reason);
        $this->assertSame((int) DB::table('audit_log')->where('id', Ids::toBinary($id))->value('seq'), $v->brokenAtSeq);
    }

    public function test_tampering_with_action_or_actor_is_detected(): void
    {
        $id = $this->write('order.void');
        DB::table('audit_log')->where('id', Ids::toBinary($id))->update(['action' => 'order.create']);
        $this->assertFalse(Audit::verifyChain()->valid);
    }

    public function test_deleting_a_middle_row_is_detected(): void
    {
        $this->write('a');
        $mid = $this->write('b');
        $this->write('c');
        DB::table('audit_log')->where('id', Ids::toBinary($mid))->delete();

        $v = Audit::verifyChain();
        $this->assertFalse($v->valid);
        $this->assertStringContainsString('prev_hash', (string) $v->reason);
    }

    public function test_truncating_the_tail_is_detected_via_the_chain_head(): void
    {
        $this->write('a');
        $tail = $this->write('b');
        DB::table('audit_log')->where('id', Ids::toBinary($tail))->delete();

        $v = Audit::verifyChain();
        $this->assertFalse($v->valid);
        $this->assertStringContainsString('audit_chain_head', (string) $v->reason);
    }

    public function test_audit_row_rolls_back_with_the_business_transaction(): void
    {
        try {
            DB::transaction(function () {
                $this->write('will-rollback');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_json_values_survive_mysql_json_normalisation(): void
    {
        // MySQL reorders JSON object keys and rewrites whitespace; verification must not care.
        $this->write('json', ['zeta' => 'z', 'alpha' => ['b' => null, 'a' => true], 'list' => [3, 1, 2]], ['unicode' => 'Ọ̀tụ́ẹ́kẹ́', 'slash' => 'a/b', 'n' => 12345678901]);
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_defaults_actor_and_tenant_from_request_context(): void
    {
        $staff = TestData::staff($this->t, 'aud');
        RequestContext::set(RequestContext::STAFF_ID, $staff->id);
        Audit::record('x', 'Thing', Ids::uuid7());
        $row = DB::table('audit_log')->first();
        $this->assertSame($staff->id, Ids::fromBinary($row->actor_staff_id));
        $this->assertSame($this->t['site'], Ids::fromBinary($row->site_id)); // single site in DB
    }

    public function test_audit_endpoints_require_permission_and_report_chain_state(): void
    {
        $itAdmin = TestData::staff($this->t, 'auditor');
        TestData::assign($itAdmin, 'IT_ADMIN', 'SITE');
        $nobody = TestData::staff($this->t, 'nobody');
        $login = fn (string $u) => $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PASSWORD', 'identifier' => $u, 'secret' => TestData::PASSWORD])->json('accessToken');
        $good = $login('auditor'); // writes a staff.login audit row
        $bad = $login('nobody');

        $this->withToken($bad)->getJson('/api/v1/audit')->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $list = $this->withToken($good)->getJson('/api/v1/audit?action=staff.login&limit=1')->assertOk();
        $this->assertSame('staff.login', $list->json('items.0.action'));
        $this->assertNotNull($list->json('nextCursor'));
        $this->withToken($good)->getJson('/api/v1/audit/verify')->assertOk()->assertJsonPath('valid', true);
    }

    public function test_audit_list_can_be_read_newest_first_and_filtered_by_date(): void
    {
        $this->write('first');
        $this->write('second');
        $this->write('third');
        DB::table('audit_log')->where('action', 'first')->update(['occurred_at' => '2020-01-02 10:00:00.000000']); // (raw update only for the test: the chain is not verified here)
        $itAdmin = TestData::staff($this->t, 'auditor2');
        TestData::assign($itAdmin, 'IT_ADMIN', 'SITE');
        $tok = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PASSWORD', 'identifier' => 'auditor2', 'secret' => TestData::PASSWORD])->json('accessToken');

        $asc = $this->withToken($tok)->getJson('/api/v1/audit?action=second')->assertOk();
        $this->assertSame('second', $asc->json('items.0.action'));
        $all = $this->withToken($tok)->getJson('/api/v1/audit?entityType=Thing')->json('items.*.action');
        $this->assertSame(['first', 'second', 'third'], $all);
        $this->assertSame(['third', 'second', 'first'], $this->withToken($tok)->getJson('/api/v1/audit?entityType=Thing&order=desc')->json('items.*.action'));
        $this->assertSame(['first'], $this->withToken($tok)->getJson('/api/v1/audit?entityType=Thing&filter[to]=2020-01-02')->json('items.*.action'));
        $this->assertSame(['third', 'second'], $this->withToken($tok)->getJson('/api/v1/audit?entityType=Thing&from=2021-01-01&order=desc')->json('items.*.action'));
        $this->withToken($tok)->getJson('/api/v1/audit?order=sideways')->assertStatus(422);
    }
}
