<?php

namespace Tests\Feature\Orders;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\OrdersFixture;
use Tests\Support\OrdersWorkers;
use Tests\Support\TestData;

/** Real concurrency: N PHP processes, N MySQL connections, released at the same instant. */
class OrdersConcurrencyTest extends ConcurrentTestCase
{
    private OrdersFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = OrdersFixture::make();
    }

    /** One app instance serves many requests in a test: drop cached guard users like a fresh request would (same as Tests\TestCase). */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function login(string $user): string
    {
        return $this->postJson('/api/v1/auth/staff/login', ['username' => $user, 'password' => TestData::PASSWORD])->json('accessToken');
    }

    /** @return array{0: string, 1: string} order id + etag */
    private function draft(string $token): array
    {
        $r = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'seed-'.uniqid()])->postJson('/api/v1/orders', [
            'facilityId' => $this->f->restaurant->id, 'tableId' => $this->f->tables['T1'], 'lines' => [['productId' => $this->f->products['jollof'], 'quantity' => 1]],
        ])->assertStatus(201);

        return [$r->json('id'), (string) $r->headers->get('ETag')];
    }

    /** @param list<array{status: int, body: mixed, replayed: ?string}> $results @return list<int> */
    private function statuses(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $out[] = $r['result']['status'];
        }
        sort($out);

        return $out;
    }

    public function test_concurrent_add_line_with_the_same_etag_only_one_wins(): void
    {
        $token = $this->login('waiter');
        [$id, $etag] = $this->draft($token);
        $results = Concurrent::run(6, OrdersWorkers::class, 'call', [$token, 'POST', "/orders/{$id}/lines", ['productId' => $this->f->products['suya'], 'quantity' => 1], ['If-Match' => $etag]]);

        $this->assertSame([201, 412, 412, 412, 412, 412], $this->statuses($results));
        $this->assertSame(2, DB::table('order_line')->where('order_id', Ids::toBinary($id))->count());
        $this->assertSame('7500.0000', DB::table('order')->where('id', Ids::toBinary($id))->value('total'));
    }

    public function test_concurrent_sends_create_one_set_of_tickets(): void
    {
        $token = $this->login('waiter');
        [$id, $etag] = $this->draft($token);
        $results = Concurrent::run(6, OrdersWorkers::class, 'call', [$token, 'POST', "/orders/{$id}/send", [], ['If-Match' => $etag]]);

        $st = $this->statuses($results);
        $this->assertSame(1, count(array_filter($st, fn ($s) => $s === 200)), 'exactly one send wins');
        $this->assertSame(0, count(array_filter($st, fn ($s) => ! in_array($s, [200, 412, 409], true))));
        $this->assertSame(1, DB::table('prep_ticket')->count());
        $this->assertSame(1, DB::table('prep_ticket_item')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'order.send')->count());
    }

    public function test_same_idempotency_key_send_replays_and_applies_once(): void
    {
        $token = $this->login('waiter');
        [$id, $etag] = $this->draft($token);
        $results = Concurrent::run(6, OrdersWorkers::class, 'call', [$token, 'POST', "/orders/{$id}/send", [], ['If-Match' => $etag, 'Idempotency-Key' => 'send-race-key-0001']]);

        $this->assertSame([200, 200, 200, 200, 200, 200], $this->statuses($results));
        $this->assertSame(1, DB::table('prep_ticket')->count());
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'OrderUpdated')->count());
    }

    public function test_add_line_racing_send_is_serialised_by_the_order_lock(): void
    {
        $token = $this->login('waiter');
        [$id, $etag] = $this->draft($token);
        $results = Concurrent::run(6, OrdersWorkers::class, 'sendOrAddLine', [$token, $id, $etag, $this->f->products['suya']]);
        $st = $this->statuses($results);
        // the shared ETag lets exactly ONE of the six mutations win; everyone else is told to reload (412)
        $this->assertSame(1, count(array_filter($st, fn ($s) => in_array($s, [200, 201], true))));
        $this->assertSame(5, count(array_filter($st, fn ($s) => $s === 412)));
        $order = DB::table('order')->where('id', Ids::toBinary($id))->first();
        $lines = DB::table('order_line')->where('order_id', $order->id)->get();
        if ($order->status === 'SENT') {
            $this->assertCount(1, $lines);
            $this->assertSame('ROUTED', $lines[0]->status);
            $this->assertSame(1, DB::table('prep_ticket_item')->count());
        } else {
            $this->assertSame('DRAFT', $order->status);
            $this->assertCount(2, $lines);
            $this->assertSame(0, DB::table('prep_ticket')->count());
        }
    }

    public function test_two_attendants_seating_the_same_table_only_one_wins(): void
    {
        $t1 = $this->login('waiter');
        $t2 = $this->login('waiter2');
        $table = $this->f->tables['T2'];
        $a = Concurrent::run(1, OrdersWorkers::class, 'call', [$t1, 'POST', "/tables/{$table}/open"]);
        // race them for a second table
        $table3 = $this->f->tables['T3'];
        $r1 = Concurrent::run(3, OrdersWorkers::class, 'call', [$t1, 'POST', "/tables/{$table3}/open"]);
        $r2 = Concurrent::run(3, OrdersWorkers::class, 'call', [$t2, 'POST', "/tables/{$table3}/open"]);
        $all = $this->statuses(array_merge($r1, $r2));
        $owner = DB::table('dining_table')->where('id', Ids::toBinary($table3))->value('occupied_by_staff_id');
        $this->assertNotNull($owner);
        // whoever got there first keeps it: their 3 calls succeed (idempotent), the other's 3 calls are 409
        $this->assertSame([200, 200, 200, 409, 409, 409], $all);
        $this->assertSame([200], $this->statuses($a));
    }

    public function test_concurrent_decisions_on_one_approval_apply_the_void_once(): void
    {
        $w = $this->login('waiter');
        [$id, $etag] = $this->draft($w);
        $sent = $this->withToken($w)->withHeaders(['Idempotency-Key' => 's-'.uniqid(), 'If-Match' => $etag])->postJson("/api/v1/orders/{$id}/send")->assertOk();
        $void = $this->withToken($w)->withHeaders(['Idempotency-Key' => 'v-'.uniqid(), 'If-Match' => (string) $sent->headers->get('ETag')])->postJson("/api/v1/orders/{$id}/void", ['reason' => 'Guest left'])->assertStatus(202);
        $aid = $void->json('approval.id');
        $sup2 = TestData::staff($this->f->t, 'supervisor2');
        TestData::assign($sup2, 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->f->restaurant->id);
        $tokens = [$this->login('supervisor'), $this->login('supervisor2'), $this->login('manager')];

        $out = [];
        foreach (Concurrent::run(3, OrdersWorkers::class, 'call', [$tokens[0], 'POST', "/approvals/{$aid}/decision", ['decision' => 'APPROVE']]) as $r) {
            $out[] = $r;
        }
        $st = $this->statuses($out);
        $this->assertSame([200, 409, 409], $st);
        $this->assertSame(1, DB::table('line_void')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'order.void')->count());
        $this->assertSame('VOIDED', DB::table('order')->where('id', Ids::toBinary($id))->value('status'));
        $this->assertSame(3, count($tokens));
    }

    public function test_concurrent_order_creation_gets_unique_sequential_numbers(): void
    {
        $token = $this->login('waiter');
        $results = Concurrent::run(8, OrdersWorkers::class, 'call', [$token, 'POST', '/orders', ['facilityId' => $this->f->restaurant->id]]);
        $this->assertSame([201, 201, 201, 201, 201, 201, 201, 201], $this->statuses($results));
        $numbers = collect($results)->map(fn ($r) => $r['result']['body']['number'])->sort()->values()->all();
        $this->assertSame(array_map(fn ($i) => sprintf('RST1-%06d', $i), range(1, 8)), $numbers);
    }
}
