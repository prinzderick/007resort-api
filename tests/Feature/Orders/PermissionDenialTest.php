<?php

namespace Tests\Feature\Orders;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;

/** Authorization is permission-based, scoped, and never role-name-based. */
class PermissionDenialTest extends OrdersTestCase
{
    public function test_a_role_named_manager_without_the_permissions_is_denied_everywhere_it_matters(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $id = $s->json('id');
        // may send (has order.send) but holds no void/approve/discount permissions
        $this->api('fakemanager', 'POST', "/orders/{$id}/void", ['reason' => 'trust me'], ['If-Match' => $this->etag($s)])
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'order.void.execute');
        $this->api('fakemanager', 'POST', "/orders/{$id}/lines/".$s->json('lines.0.id').'/adjustments', ['kind' => 'DISCOUNT_PERCENT', 'value' => '50', 'reason' => 'my mate'], ['If-Match' => $this->etag($s)])
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied');

        // a real approval exists; the fake manager cannot decide it
        $r = $this->api('waiter', 'POST', "/orders/{$id}/void", ['reason' => 'Guest left'], ['If-Match' => $this->etag($s)])->assertStatus(202);
        $this->api('fakemanager', 'POST', '/approvals/'.$r->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->api('fakemanager', 'GET', '/approvals/'.$r->json('approval.id'))->assertStatus(403);
        $this->assertSame([], $this->api('fakemanager', 'GET', '/approvals?scope=approvable')->json('items'));
        $this->api('fakemanager', 'PUT', "/catalog/products/{$this->f->products['jollof']}/availability/{$this->f->restaurant->id}", ['available' => false])->assertStatus(403);
        $this->api('fakemanager', 'PATCH', "/tables/{$this->f->tables['T1']}", ['status' => 'FREE'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->assertSame('PENDING_APPROVAL', $this->api('supervisor', 'GET', "/orders/{$id}")->json('status'));
    }

    public function test_a_real_manager_with_the_permissions_can(): void
    {
        $s = $this->sent(['jollof' => 1]);
        $this->api('manager', 'POST', '/orders/'.$s->json('id').'/void', ['reason' => 'Owner request'], ['If-Match' => $this->etag($s)])->assertOk()->assertJsonPath('status', 'VOIDED');
    }

    public function test_facility_scope_is_enforced(): void
    {
        // the waiter is scoped to the restaurant only
        $this->api('waiter', 'POST', '/orders', ['facilityId' => $this->f->club->id])->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->api('waiter', 'GET', "/tables?facilityId={$this->f->club->id}")->assertStatus(403);
        $order = $this->draft(['jollof' => 1]);
        // a supervisor of the club (other facility) cannot decide/approve restaurant approvals
        $clubSup = TestData::staff($this->f->t, 'clubsup');
        TestData::assign($clubSup, 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->f->club->id);
        $this->api('clubsup', 'GET', '/orders/'.$order->json('id'))->assertStatus(403);
        $sent = $this->send($order->json('id'), $this->etag($order))->assertOk();
        $r = $this->api('waiter', 'POST', '/orders/'.$order->json('id').'/void', ['reason' => 'Guest left'], ['If-Match' => $this->etag($sent)])->assertStatus(202);
        $this->api('clubsup', 'POST', '/approvals/'.$r->json('approval.id').'/decision', ['decision' => 'APPROVE'])->assertStatus(403);
    }

    public function test_kitchen_staff_cannot_create_orders_and_waiters_cannot_drive_the_kds(): void
    {
        $this->api('chef', 'POST', '/orders', ['facilityId' => $this->f->restaurant->id])->assertStatus(403);
        $s = $this->sent(['jollof' => 1]);
        $tid = Ids::fromBinary(DB::table('prep_ticket')->value('id'));
        $this->api('waiter', 'POST', "/prep-tickets/{$tid}/transition", ['to' => 'ACCEPTED'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->api('chef', 'POST', '/orders/'.$s->json('id').'/send', [], ['If-Match' => $this->etag($s)])->assertStatus(403);
    }

    public function test_unauthenticated_requests_are_401(): void
    {
        $this->getJson('/api/v1/orders')->assertStatus(401);
        $this->getJson("/api/v1/tables?facilityId={$this->f->restaurant->id}")->assertStatus(401);
    }

    public function test_mutations_require_an_idempotency_key(): void
    {
        $this->withToken($this->token('waiter'))->postJson('/api/v1/orders', ['facilityId' => $this->f->restaurant->id])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
    }
}
