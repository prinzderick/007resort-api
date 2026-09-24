<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

class TicketTypeAdminTest extends ConfigTestCase
{
    public function test_crud_price_and_validation(): void
    {
        $o = $this->owner();
        $pool = DemoIds::facility('POOL_AREA');
        $r = $o->post('/ticketing/ticket-types', ['code' => 'pool-kids', 'name' => 'Pool (kids)', 'facilityId' => $pool, 'validationMode' => 'ENTRY_EXIT', 'validityKind' => 'DURATION_MINUTES', 'validityMinutes' => 180, 'earlyEntryMinutes' => 10, 'price' => '2500']);
        $r->assertStatus(201);
        $this->assertSame('POOL-KIDS', $r->json('code'));
        $this->assertSame('2500.0000', $r->json('price'));
        $this->assertNotNull($r->json('productId'));
        $p = DB::table('product')->where('id', Ids::toBinary($r->json('productId')))->first();
        $this->assertSame('TICKET', $p->kind);
        $this->assertSame('TKT-POOL-KIDS', $p->sku);
        $this->assertSame('2500.0000', app(\App\Domain\Catalog\Services\Pricing::class)->unitPrice($r->json('productId'), $pool));
        $id = $r->json('id');

        $o->post('/ticketing/ticket-types', ['code' => 'POOL-KIDS', 'name' => 'dup', 'facilityId' => $pool])->assertStatus(409)->assertJsonPath('code', 'ticket_type_code_taken');
        $o->post('/ticketing/ticket-types', ['code' => 'BAD1', 'name' => 'x', 'facilityId' => $pool, 'validityKind' => 'DURATION_MINUTES'])->assertStatus(422)->assertJsonValidationErrors(['validityMinutes']);
        $o->post('/ticketing/ticket-types', ['code' => 'BAD2', 'name' => 'x', 'facilityId' => $pool, 'validityKind' => 'ISSUE_DAY', 'validityMinutes' => 5])->assertStatus(422);
        $o->post('/ticketing/ticket-types', ['code' => 'BAD3', 'name' => 'x', 'facilityId' => $pool, 'validationMode' => 'WHATEVER'])->assertStatus(422);
        $o->post('/ticketing/ticket-types', ['code' => 'BAD4', 'name' => 'x', 'facilityId' => DemoIds::facility('MAIN_STORE')])->assertStatus(422)->assertJsonPath('code', 'capability_disabled');
        $o->post('/ticketing/ticket-types', ['code' => 'BAD5', 'name' => 'x', 'facilityId' => $pool, 'price' => '-1'])->assertStatus(422);

        $o->patch("/ticketing/ticket-types/{$id}", ['name' => 'No header'])->assertStatus(428);
        $u = $o->patch("/ticketing/ticket-types/{$id}", ['name' => 'Pool kids day', 'price' => '3000', 'active' => false], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame('3000.0000', $u->json('price'));
        $this->assertFalse($u->json('active'));
        $this->assertSame('3000.0000', app(\App\Domain\Catalog\Services\Pricing::class)->unitPrice($r->json('productId'), $pool));
        $o->patch("/ticketing/ticket-types/{$id}", ['name' => 'Stale'], ['If-Match' => '"1"'])->assertStatus(412);
        $o->patch("/ticketing/ticket-types/{$id}", ['code' => 'NEWCODE'], ['If-Match' => '"2"'])->assertStatus(422);
        $this->assertSame([1, 2], array_column($this->outbox($id, 'ticketType'), 'version'));
        $this->assertCount(1, $this->audit('config.ticket_type.update', $id));

        $list = $o->get("/ticketing/ticket-types?facilityId={$pool}")->assertOk()->json('items');
        $this->assertContains('POOL-KIDS', array_column($list, 'code'));
        $this->assertNotContains('POOL-KIDS', array_column($o->get("/ticketing/ticket-types?facilityId={$pool}&active=true")->json('items'), 'code'));

        // facility scope cannot change once tickets exist
        $used = DB::table('entitlement_item')->whereNotNull('ticket_type_id')->first();
        if ($used !== null) {
            $type = DB::table('ticket_type')->where('id', $used->ticket_type_id)->first();
            $other = Ids::fromBinary($type->facility_unit_id) === $pool ? DemoIds::facility('RECEPTION') : $pool;
            $o->patch('/ticketing/ticket-types/'.Ids::fromBinary($type->id), ['facilityId' => $other], ['If-Match' => '"'.$type->row_version.'"'])->assertStatus(409)->assertJsonPath('code', 'ticket_type_in_use');
        }
    }

    public function test_permissions(): void
    {
        $m = $this->managerLacking(['ticket_type.manage']);
        $m->post('/ticketing/ticket-types', ['code' => 'NOPE', 'name' => 'n', 'facilityId' => DemoIds::facility('POOL_AREA')])->assertStatus(403)->assertJsonPath('permission', 'ticket_type.manage');
        $m->get('/ticketing/ticket-types')->assertOk();
        $this->api('cashier1')->get('/ticketing/ticket-types')->assertOk(); // ticket.issue holders can list them
        $this->api('wait1')->get('/ticketing/ticket-types')->assertStatus(403);
    }
}
