<?php

namespace Tests\Feature\Orders;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class ClientIdAndIdempotencyTest extends OrdersTestCase
{
    private function body(string $id, int $qty = 1): array
    {
        return ['id' => $id, 'clientCreatedAt' => '2026-09-23T10:00:00Z', 'facilityId' => $this->f->restaurant->id, 'tableId' => $this->f->tables['T1'],
            'lines' => [['productId' => $this->f->products['jollof'], 'quantity' => $qty]]];
    }

    public function test_client_supplied_order_id_is_used_and_replayed_without_duplicates(): void
    {
        $id = Ids::uuid7();
        $a = $this->api('waiter', 'POST', '/orders', $this->body($id))->assertStatus(201);
        $this->assertSame($id, $a->json('id'));
        // same id + same body, NEW idempotency key (offline replay): original resource, no duplicate
        $b = $this->api('waiter', 'POST', '/orders', $this->body($id))->assertOk();
        $this->assertSame($a->json('number'), $b->json('number'));
        $this->assertSame($a->json('lines.0.id'), $b->json('lines.0.id'));
        $this->assertSame(1, DB::table('order')->count());
        $this->assertSame(1, DB::table('order_line')->count());
    }

    public function test_same_client_id_with_a_different_body_is_a_409_concurrency_conflict(): void
    {
        $id = Ids::uuid7();
        $this->api('waiter', 'POST', '/orders', $this->body($id, 1))->assertStatus(201);
        $this->api('waiter', 'POST', '/orders', $this->body($id, 2))->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
        $this->assertSame(1, DB::table('order')->count());
        $this->assertSame('1', (string) DB::table('order_line')->value('quantity'));
    }

    public function test_reusing_a_line_id_on_a_new_order_is_a_409_not_a_500(): void
    {
        $lineId = Ids::uuid7();
        $mk = fn (string $oid) => ['id' => $oid, 'facilityId' => $this->f->restaurant->id, 'tableId' => $this->f->tables['T1'],
            'lines' => [['id' => $lineId, 'productId' => $this->f->products['jollof'], 'quantity' => 1]]];
        $this->api('waiter', 'POST', '/orders', $mk(Ids::uuid7()))->assertStatus(201);
        $this->api('waiter', 'POST', '/orders', $mk(Ids::uuid7()))->assertStatus(409)->assertJsonPath('code', 'line_id_in_use');
        $this->assertSame(1, DB::table('order')->count());
    }

    public function test_client_id_must_be_a_uuid_v7(): void
    {
        $this->api('waiter', 'POST', '/orders', $this->body('not-a-uuid'))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $v4 = '3f2b8c1e-5d4a-4b6c-9e7f-1a2b3c4d5e6f';
        $this->api('waiter', 'POST', '/orders', $this->body($v4))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, DB::table('order')->count());
    }

    public function test_client_supplied_line_id_is_replay_safe_and_conflicts_on_a_different_body(): void
    {
        $d = $this->draft(['jollof' => 1]);
        $oid = $d->json('id');
        $lineId = Ids::uuid7();
        $line = ['id' => $lineId, 'productId' => $this->f->products['suya'], 'quantity' => 2];
        $a = $this->api('waiter', 'POST', "/orders/{$oid}/lines", $line, ['If-Match' => $this->etag($d)])->assertStatus(201);
        $this->assertSame($lineId, $a->json('lines.1.id'));
        // replay (stale ETag is fine: the id already exists with the same body)
        $b = $this->api('waiter', 'POST', "/orders/{$oid}/lines", $line, ['If-Match' => $this->etag($d)])->assertOk();
        $this->assertCount(2, $b->json('lines'));
        $this->assertSame(2, DB::table('order_line')->count());
        $this->api('waiter', 'POST', "/orders/{$oid}/lines", ['quantity' => 5] + $line, ['If-Match' => $this->etag($a)])->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
    }

    public function test_idempotency_key_replays_the_original_response(): void
    {
        $key = 'replay-key-'.str_repeat('a', 12);
        $body = ['facilityId' => $this->f->restaurant->id, 'tableId' => $this->f->tables['T2']];
        $a = $this->api('waiter', 'POST', '/orders', $body, ['Idempotency-Key' => $key])->assertStatus(201);
        $b = $this->api('waiter', 'POST', '/orders', $body, ['Idempotency-Key' => $key])->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, DB::table('order')->count());
        $this->api('waiter', 'POST', '/orders', ['facilityId' => $this->f->restaurant->id], ['Idempotency-Key' => $key])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_a_202_pending_approval_is_stored_and_replayed_without_a_second_approval(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $key = 'void-key-'.str_repeat('b', 12);
        $h = ['If-Match' => $this->etag($s), 'Idempotency-Key' => $key];
        $a = $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'Guest left'], $h)->assertStatus(202);
        $b = $this->api('waiter', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'Guest left'], $h)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('approval.id'), $b->json('approval.id'));
        $this->assertSame(1, DB::table('approval')->whereNotNull('action')->count());
    }
}
