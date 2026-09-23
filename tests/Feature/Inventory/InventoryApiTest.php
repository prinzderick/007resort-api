<?php

namespace Tests\Feature\Inventory;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryData;
use Tests\Support\TestData;
use Tests\TestCase;

/** HTTP contract: permissions (scoped), idempotency, adjustments + approval, wastage, returns, counts, master data. */
class InventoryApiTest extends TestCase
{
    private array $t;

    private $main;

    private $bar;

    private $salon;

    private $barFacility;

    private $beer;

    private $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
        $this->barFacility = TestData::facility($this->t, 'bar');
        $salonFacility = TestData::facility($this->t, 'salon');
        $this->main = InventoryData::location($this->t, 'Main Store', 'MAIN_STORE', null, false, false);
        $this->bar = InventoryData::location($this->t, 'Bar Store', 'BAR', $this->barFacility->id);
        $this->salon = InventoryData::location($this->t, 'Salon Store', 'FACILITY_STORE', $salonFacility->id);
        $this->beer = InventoryData::item($this->t, 'BEER', 'Beer', 'bottle', '5');
        $this->wine = InventoryData::item($this->t, 'WINE', 'Wine', 'bottle');
    }

    /** @return string bearer token */
    private function as(string $user, string $role, string $level = 'SITE', ?string $facilityId = null): string
    {
        $staff = TestData::staff($this->t, $user);
        TestData::assign($staff, $role, $level, $facilityId);

        return $this->postJson('/api/v1/auth/staff/login', ['username' => $user, 'password' => TestData::PASSWORD])->json('accessToken');
    }

    private function send(string $token, string $uri, array $body, ?string $key = null)
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', $key ?? Ids::uuid7())->postJson($uri, $body);
    }

    public function test_unauthenticated_and_unauthorised_callers_are_rejected(): void
    {
        $this->getJson('/api/v1/inventory/items')->assertStatus(401);
        $waiter = $this->as('waiter', 'WAIT_STAFF');
        $this->withToken($waiter)->getJson('/api/v1/inventory/items')->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'inventory.view');
        $this->send($waiter, '/api/v1/inventory/transfers', [])->assertStatus(403);
    }

    public function test_mutations_require_an_idempotency_key(): void
    {
        $keeper = $this->as('keeper', 'STOREKEEPER');
        $this->withToken($keeper)->postJson('/api/v1/inventory/wastage', [])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
    }

    public function test_purchase_receipt_via_api_and_idempotent_replay_applies_once(): void
    {
        $keeper = $this->as('keeper', 'STOREKEEPER');
        $body = ['locationId' => $this->main->id, 'supplierName' => 'Acme', 'supplierInvoice' => 'A-1', 'lines' => [['itemId' => $this->beer->id, 'quantity' => '12', 'unitCost' => ['amount' => '600', 'currency' => 'NGN']]]];

        $first = $this->send($keeper, '/api/v1/inventory/purchase-receipts', $body, 'rcpt-1')->assertStatus(201);
        $replay = $this->send($keeper, '/api/v1/inventory/purchase-receipts', $body, 'rcpt-1')->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('id'), $replay->json('id'));
        $first->assertJsonPath('kind', 'PURCHASE_RECEIPT')->assertJsonPath('status', 'POSTED')->assertJsonPath('lines.0.quantityDelta', '12.0000')
            ->assertJsonPath('lines.0.unitCost.amount', '600.0000');
        $this->assertSame('12.0000', InventoryData::onHand($this->main->id, $this->beer->id), 'replay must not double-receive');
        $this->assertSame(1, DB::table('purchase_receipt')->count());

        // same key, different body -> 422
        $this->send($keeper, '/api/v1/inventory/purchase-receipts', ['locationId' => $this->main->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '1']]], 'rcpt-1')
            ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_validation_errors_are_problem_json(): void
    {
        $keeper = $this->as('keeper', 'STOREKEEPER');
        $this->send($keeper, '/api/v1/inventory/purchase-receipts', ['locationId' => $this->main->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '-3']]])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->send($keeper, '/api/v1/inventory/purchase-receipts', ['locationId' => $this->main->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '1.23456']]])
            ->assertStatus(422);
        $this->send($keeper, '/api/v1/inventory/purchase-receipts', ['locationId' => $this->main->id, 'lines' => []])->assertStatus(422);
    }

    public function test_facility_scoped_keeper_cannot_move_stock_they_do_not_control(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '20');
        InventoryData::stock($this->bar->id, $this->beer->id, '20');
        $barKeeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);

        // may send out of the bar store (their facility)...
        $this->send($barKeeper, '/api/v1/inventory/transfers', ['fromLocationId' => $this->bar->id, 'toLocationId' => $this->salon->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '2']]])->assertStatus(201);
        // ...but not out of the Main Store (site scope) or the salon store (another facility)
        $this->send($barKeeper, '/api/v1/inventory/transfers', ['fromLocationId' => $this->main->id, 'toLocationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '2']]])
            ->assertStatus(403)->assertJsonPath('code', 'permission_denied');
        $this->send($barKeeper, '/api/v1/inventory/transfers', ['fromLocationId' => $this->salon->id, 'toLocationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '1']]])
            ->assertStatus(403);
        $this->assertSame('20.0000', InventoryData::onHand($this->main->id, $this->beer->id));
    }

    public function test_view_endpoints_are_filtered_to_locations_the_caller_may_view(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '7');
        InventoryData::stock($this->bar->id, $this->beer->id, '3');
        InventoryData::stock($this->salon->id, $this->wine->id, '9');
        $barKeeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $owner = $this->as('boss', 'OWNER', 'ORGANIZATION');

        $mine = $this->withToken($barKeeper)->getJson('/api/v1/inventory/balances')->assertOk();
        $this->assertSame([$this->bar->id], collect($mine->json('items'))->pluck('locationId')->all());
        $this->assertCount(3, $this->withToken($owner)->getJson('/api/v1/inventory/balances')->assertOk()->json('items'));
        $this->withToken($barKeeper)->getJson('/api/v1/inventory/balances?locationId='.$this->salon->id)->assertStatus(403);
        $this->assertSame([$this->bar->id], collect($this->withToken($barKeeper)->getJson('/api/v1/inventory/locations')->json('items'))->pluck('id')->all());
    }

    public function test_balances_shape_filters_and_pagination(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '4');
        InventoryData::stock($this->bar->id, $this->beer->id, '40');
        InventoryData::stock($this->main->id, $this->wine->id, '8');
        $owner = $this->as('boss', 'OWNER', 'ORGANIZATION');

        $r = $this->withToken($owner)->getJson('/api/v1/inventory/balances?itemId='.$this->beer->id)->assertOk();
        $this->assertCount(2, $r->json('items'));
        $row = collect($r->json('items'))->firstWhere('locationId', $this->main->id);
        $this->assertSame(['itemId', 'itemName', 'locationId', 'quantity', 'unit', 'reorderLevel', 'belowReorder', 'updatedAt'], array_keys($row));
        $this->assertSame('4.0000', $row['quantity']);
        $this->assertTrue($row['belowReorder']);

        $low = $this->withToken($owner)->getJson('/api/v1/inventory/balances?belowReorder=1')->json('items');
        $this->assertCount(1, $low); // beer@main only: 4 <= 5; beer@bar 40 and wine 8 (reorder 0) are above their levels
        $this->assertSame($this->main->id, $low[0]['locationId']);
    }

    public function test_balances_cursor_pagination_walks_every_row_once(): void
    {
        foreach (range(1, 5) as $i) {
            $it = InventoryData::item($this->t, "P{$i}", "Item {$i}");
            InventoryData::stock($this->main->id, $it->id, (string) $i);
        }
        $owner = $this->as('boss', 'OWNER', 'ORGANIZATION');
        $seen = [];
        $cursor = null;
        do {
            $r = $this->withToken($owner)->getJson('/api/v1/inventory/balances?limit=2'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
            foreach ($r->json('items') as $row) {
                $seen[] = $row['itemId'];
            }
            $cursor = $r->json('nextCursor');
        } while ($cursor);
        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_items_search_create_update_with_if_match(): void
    {
        $procurement = $this->as('buyer', 'PROCUREMENT');
        $r = $this->send($procurement, '/api/v1/inventory/items', ['sku' => 'GIN-1', 'name' => 'Gin 75cl', 'unit' => 'bottle', 'reorderLevel' => '6', 'category' => 'Spirits'])->assertStatus(201);
        $id = $r->json('id');
        $r->assertJsonPath('sku', 'GIN-1')->assertJsonPath('reorderLevel', '6.0000')->assertJsonPath('active', true)->assertJsonPath('rowVersion', 1);
        $this->send($procurement, '/api/v1/inventory/items', ['sku' => 'GIN-1', 'name' => 'dup'])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.sku.0', 'An item with that SKU already exists.');

        $this->withToken($procurement)->getJson('/api/v1/inventory/items?q=gin')->assertOk()->assertJsonPath('items.0.id', $id)->assertJsonPath('nextCursor', null);

        $patch = fn (array $b, string $ifMatch) => $this->withToken($procurement)->withHeaders(['Idempotency-Key' => Ids::uuid7(), 'If-Match' => $ifMatch])->patchJson("/api/v1/inventory/items/{$id}", $b);
        $patch(['name' => 'Gin 1L'], '"1"')->assertOk()->assertJsonPath('name', 'Gin 1L')->assertJsonPath('rowVersion', 2)->assertHeader('ETag', '"2"');
        $patch(['name' => 'stale'], '"1"')->assertStatus(412)->assertJsonPath('code', 'concurrency_conflict');
        $this->withToken($procurement)->withHeaders(['Idempotency-Key' => Ids::uuid7(), 'If-Match' => ''])->patchJson("/api/v1/inventory/items/{$id}", ['name' => 'no header'])
            ->assertStatus(428)->assertJsonPath('code', 'concurrency_conflict');
        $this->assertSame(2, DB::table('audit_log')->whereIn('action', ['inventory.item.create', 'inventory.item.update'])->count());
    }

    public function test_only_location_managers_change_locations_and_allow_negative_is_audited(): void
    {
        $keeper = $this->as('keeper', 'STOREKEEPER');
        $this->send($keeper, '/api/v1/inventory/locations', ['name' => 'X', 'kind' => 'FACILITY_STORE', 'facilityId' => $this->barFacility->id])->assertStatus(403);

        $manager = $this->as('mgr', 'MANAGER');
        $r = $this->send($manager, '/api/v1/inventory/locations', ['name' => 'Pool Bar Store', 'kind' => 'BAR', 'facilityId' => $this->barFacility->id, 'allowNegative' => true])->assertStatus(201);
        $r->assertJsonPath('allowNegative', true)->assertJsonPath('kind', 'BAR');
        $this->send($manager, '/api/v1/inventory/locations', ['name' => 'Bad', 'kind' => 'MAIN_STORE', 'facilityId' => $this->barFacility->id])->assertStatus(422);
        $this->send($manager, '/api/v1/inventory/locations', ['name' => 'Pool Bar Store', 'kind' => 'BAR', 'facilityId' => $this->barFacility->id])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.name.0', 'A stock location with that name already exists.');

        $this->withToken($manager)->withHeaders(['Idempotency-Key' => Ids::uuid7(), 'If-Match' => '"1"'])->patchJson('/api/v1/inventory/locations/'.$r->json('id'), ['allowNegative' => false])
            ->assertOk()->assertJsonPath('allowNegative', false);
        $a = DB::table('audit_log')->where('action', 'inventory.location.update')->first();
        $this->assertNotNull($a);
        $this->assertTrue(json_decode($a->old_value, true)['allowNegative']);
        $this->assertFalse(json_decode($a->new_value, true)['allowNegative']);
    }

    public function test_wastage_and_returns(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '5');
        $sup = $this->as('sup', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->barFacility->id);

        $this->send($sup, '/api/v1/inventory/wastage', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantity' => '2', 'reason' => 'BREAKAGE', 'note' => 'dropped crate'])
            ->assertStatus(201)->assertJsonPath('kind', 'WASTAGE')->assertJsonPath('lines.0.quantityDelta', '-2.0000');
        $this->assertSame('3.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame('BREAKAGE: dropped crate', DB::table('stock_movement')->where('reason', 'WASTAGE')->value('note'));
        // cannot waste what is not there
        $this->send($sup, '/api/v1/inventory/wastage', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantity' => '4', 'reason' => 'SPOILAGE'])
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('meta.itemId', $this->beer->id)->assertJsonPath('meta.availableQuantity', '3.0000');
        $this->assertSame('3.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        // wrong facility
        $this->send($sup, '/api/v1/inventory/wastage', ['locationId' => $this->salon->id, 'itemId' => $this->beer->id, 'quantity' => '1', 'reason' => 'OTHER'])->assertStatus(403);

        $this->send($sup, '/api/v1/inventory/returns', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantity' => '1', 'kind' => 'CUSTOMER', 'note' => 'unopened'])->assertStatus(201)->assertJsonPath('kind', 'RETURN');
        $this->assertSame('4.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->send($sup, '/api/v1/inventory/returns', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantity' => '3', 'kind' => 'SUPPLIER'])->assertStatus(201);
        $this->assertSame('1.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
    }

    // ---- adjustments + approval ----------------------------------------------------------------------------------

    public function test_adjustment_by_a_requester_without_approval_permission_is_pending_and_touches_nothing(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        $keeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $movements = DB::table('stock_movement')->count();

        $r = $this->send($keeper, '/api/v1/inventory/adjustments', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantityDelta' => '-3', 'reason' => 'THEFT', 'note' => 'missing after party'])
            ->assertStatus(202)->assertJsonPath('status', 'PENDING_APPROVAL')->assertJsonPath('approval.status', 'PENDING')
            ->assertJsonPath('approval.requiredPermission', 'inventory.adjustment.approve')->assertJsonPath('movement.status', 'PENDING_APPROVAL');

        $this->assertSame($movements, DB::table('stock_movement')->count(), 'nothing on the ledger until approved');
        $this->assertSame('10.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertNotNull($r->json('approval.id'));
        $this->assertSame(1, DB::table('approval')->where('status', 'PENDING')->where('action', 'inventory.adjustment')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inventory.adjustment.create')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inventory.adjustment.request')->count(), 'the approval request itself is audited by the approval workflow');
    }

    public function test_supervisor_approves_a_pending_adjustment_and_it_posts_with_the_approval_link(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        $keeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $sup = $this->as('supervisor', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->barFacility->id);
        $req = $this->send($keeper, '/api/v1/inventory/adjustments', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantityDelta' => '-3', 'reason' => 'DAMAGE', 'note' => 'leaking crate']);
        $adj = $req->json('movement.id');
        $approval = $req->json('approval.id');

        // the requester cannot decide (and lacks the permission anyway)
        $this->send($keeper, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'APPROVE'])->assertStatus(403);
        // supervisor at ANOTHER facility cannot either
        $other = $this->as('otherSup', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->salon->facility_unit_id);
        $this->send($other, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'APPROVE'])->assertStatus(403);

        $this->send($sup, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'APPROVE', 'note' => 'seen it'])
            ->assertOk()->assertJsonPath('status', 'POSTED')->assertJsonPath('approvalId', $approval)->assertJsonPath('lines.0.quantityDelta', '-3.0000');

        $this->assertSame('7.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $m = DB::table('stock_movement')->where('reason', 'ADJUSTMENT')->first();
        $this->assertSame(Ids::toBinary($approval), $m->approval_id);
        $this->assertSame('APPROVED', DB::table('approval')->where('id', Ids::toBinary($approval))->value('status'));
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'StockAdjusted')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inventory.adjustment.post')->whereNotNull('approval_id')->count());

        // a decided adjustment cannot be decided twice
        $this->send($sup, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'REJECT'])->assertStatus(409)->assertJsonPath('code', 'approval_already_decided');
        $this->assertSame('7.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
    }

    public function test_rejected_adjustment_never_touches_stock(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        $keeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $sup = $this->as('supervisor', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->barFacility->id);
        $adj = $this->send($keeper, '/api/v1/inventory/adjustments', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantityDelta' => '5', 'reason' => 'CORRECTION', 'note' => 'found a crate'])->json('movement.id');

        $this->send($sup, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'REJECT', 'note' => 'no evidence'])->assertOk()->assertJsonPath('status', 'REJECTED');

        $this->assertSame('10.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame('REJECTED', DB::table('approval')->value('status'));
        $this->assertSame(0, DB::table('stock_movement')->where('reason', 'ADJUSTMENT')->count());
    }

    public function test_requester_who_holds_the_approve_permission_posts_immediately(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        $manager = $this->as('mgr', 'MANAGER');

        $this->send($manager, '/api/v1/inventory/adjustments', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantityDelta' => '2.5', 'reason' => 'CORRECTION', 'note' => 'recount'])
            ->assertStatus(201)->assertJsonPath('status', 'POSTED')->assertJsonPath('approvalId', null);
        $this->assertSame('12.5000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame(0, DB::table('approval')->count());
    }

    public function test_approving_a_negative_adjustment_that_no_longer_fits_stays_pending(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '2');
        $keeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $sup = $this->as('supervisor', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->barFacility->id);
        $adj = $this->send($keeper, '/api/v1/inventory/adjustments', ['locationId' => $this->bar->id, 'itemId' => $this->beer->id, 'quantityDelta' => '-5', 'reason' => 'EXPIRY', 'note' => 'expired stock'])->json('movement.id');

        $this->send($sup, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'APPROVE'])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame('PENDING_APPROVAL', DB::table('stock_adjustment')->value('status'));
        $this->assertSame('PENDING', DB::table('approval')->value('status'));
        $this->assertSame('2.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
    }

    public function test_adjustment_validation(): void
    {
        $keeper = $this->as('keeper', 'STOREKEEPER');
        foreach ([
            ['quantityDelta' => '0', 'reason' => 'OTHER', 'note' => 'abc'],
            ['quantityDelta' => '1', 'reason' => 'NOPE', 'note' => 'abc'],
            ['quantityDelta' => '1', 'reason' => 'OTHER', 'note' => 'ab'],
        ] as $bad) {
            $this->send($keeper, '/api/v1/inventory/adjustments', ['locationId' => $this->main->id, 'itemId' => $this->beer->id] + $bad)->assertStatus(422);
        }
    }

    // ---- counts ---------------------------------------------------------------------------------------------------

    public function test_count_within_threshold_posts_variance_movements_and_balance_equals_counted(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '100');
        InventoryData::stock($this->bar->id, $this->wine->id, '50');
        $keeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);

        $c = $this->send($keeper, '/api/v1/inventory/counts', ['locationId' => $this->bar->id, 'lines' => [
            ['itemId' => $this->beer->id, 'countedQuantity' => '97'],   // -3 of 100 = 3% (<=5%)
            ['itemId' => $this->wine->id, 'countedQuantity' => '50'],   // no variance
        ]])->assertStatus(201)->assertJsonPath('status', 'DRAFT');
        $this->assertSame('100.0000', collect($c->json('lines'))->firstWhere('itemId', $this->beer->id)['expectedQuantity']);
        $this->assertSame('100.0000', InventoryData::onHand($this->bar->id, $this->beer->id), 'a draft count changes nothing');

        $p = $this->send($keeper, '/api/v1/inventory/counts/'.$c->json('id').'/post', [])->assertOk()->assertJsonPath('status', 'POSTED');
        $beer = collect($p->json('lines'))->firstWhere('itemId', $this->beer->id);
        $this->assertSame('-3.0000', $beer['variance']);
        $this->assertSame('POSTED', $beer['varianceStatus']);
        $this->assertSame('NONE', collect($p->json('lines'))->firstWhere('itemId', $this->wine->id)['varianceStatus']);
        $this->assertSame('97.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame(1, DB::table('stock_movement')->where('reason', 'COUNT')->count());
        $this->assertNotNull($p->json('postedAt'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inventory.count.post')->count());

        $this->send($keeper, '/api/v1/inventory/counts/'.$c->json('id').'/post', [])->assertStatus(409)->assertJsonPath('code', 'concurrency_conflict');
    }

    public function test_count_variance_above_threshold_becomes_a_pending_adjustment_then_posts_on_approval(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '100');
        $keeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $sup = $this->as('supervisor', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->barFacility->id);
        $c = $this->send($keeper, '/api/v1/inventory/counts', ['locationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'countedQuantity' => '80']]])->json('id');

        $p = $this->send($keeper, "/api/v1/inventory/counts/{$c}/post", [])->assertOk();
        $line = $p->json('lines.0');
        $this->assertSame('-20.0000', $line['variance']);
        $this->assertSame('PENDING_APPROVAL', $line['varianceStatus']);
        $this->assertNotNull($p->json('approvalId'));
        $this->assertSame('100.0000', InventoryData::onHand($this->bar->id, $this->beer->id), 'not applied before approval');

        $adj = $p->json('adjustmentId');
        $this->send($sup, "/api/v1/inventory/adjustments/{$adj}/decision", ['decision' => 'APPROVE'])->assertOk()->assertJsonPath('kind', 'COUNT_VARIANCE');
        $this->assertSame('80.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame('COUNT', DB::table('stock_movement')->orderByDesc('id')->value('reason'));
    }

    public function test_count_variance_posted_by_an_approver_applies_directly(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '100');
        $sup = $this->as('supervisor', 'UNIT_SUPERVISOR', 'FACILITY_UNIT', $this->barFacility->id);
        $c = $this->send($sup, '/api/v1/inventory/counts', ['locationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'countedQuantity' => '60']]])->json('id');

        $this->send($sup, "/api/v1/inventory/counts/{$c}/post", [])->assertOk()->assertJsonPath('lines.0.varianceStatus', 'POSTED')->assertJsonPath('approvalId', null);
        $this->assertSame('60.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
    }

    public function test_counting_an_item_with_no_stock_row_and_expected_zero_needs_approval_and_counts_can_show_gains(): void
    {
        $keeper = $this->as('keeper', 'STOREKEEPER');
        $c = $this->send($keeper, '/api/v1/inventory/counts', ['locationId' => $this->main->id, 'lines' => [['itemId' => $this->wine->id, 'countedQuantity' => '12']]])->json('id');
        $p = $this->send($keeper, "/api/v1/inventory/counts/{$c}/post", [])->assertOk();
        $this->assertSame('12.0000', $p->json('lines.0.variance'));
        $this->assertSame('PENDING_APPROVAL', $p->json('lines.0.varianceStatus'));
        $this->assertSame('0.0000', InventoryData::onHand($this->main->id, $this->wine->id));
    }

    public function test_count_permissions_and_validation(): void
    {
        $waiter = $this->as('waiter', 'WAIT_STAFF');
        $this->send($waiter, '/api/v1/inventory/counts', ['locationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'countedQuantity' => '1']]])->assertStatus(403);
        $barKeeper = $this->as('barkeeper', 'STOREKEEPER', 'FACILITY_UNIT', $this->barFacility->id);
        $this->send($barKeeper, '/api/v1/inventory/counts', ['locationId' => $this->salon->id, 'lines' => [['itemId' => $this->beer->id, 'countedQuantity' => '1']]])->assertStatus(403);
        $this->send($barKeeper, '/api/v1/inventory/counts', ['locationId' => $this->bar->id, 'lines' => [
            ['itemId' => $this->beer->id, 'countedQuantity' => '1'], ['itemId' => $this->beer->id, 'countedQuantity' => '2'],
        ]])->assertStatus(422);
        $this->send($barKeeper, '/api/v1/inventory/counts', ['locationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'countedQuantity' => '-1']]])->assertStatus(422);
        $this->withToken($barKeeper)->getJson('/api/v1/inventory/counts/'.Ids::uuid7())->assertStatus(404);
    }

    public function test_movements_ledger_endpoint_lists_newest_first(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '5');
        $keeper = $this->as('keeper', 'STOREKEEPER');
        $this->send($keeper, '/api/v1/inventory/transfers', ['fromLocationId' => $this->main->id, 'toLocationId' => $this->bar->id, 'lines' => [['itemId' => $this->beer->id, 'quantity' => '2']]])->assertStatus(201);
        $owner = $this->as('boss', 'OWNER', 'ORGANIZATION');

        $r = $this->withToken($owner)->getJson('/api/v1/inventory/movements?itemId='.$this->beer->id)->assertOk();
        $this->assertCount(3, $r->json('items'));
        $this->assertSame('TRANSFER_IN', $r->json('items.0.reason'));
        $this->assertSame('TRANSFER_IN', $r->json('items.0.kind'));
        $this->assertSame('2.0000', $r->json('items.0.balanceAfter'));
        $this->withToken($owner)->getJson('/api/v1/inventory/movements?reason=RECEIPT')->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_suppliers_list_and_create(): void
    {
        $buyer = $this->as('buyer', 'PROCUREMENT');
        $this->send($buyer, '/api/v1/inventory/suppliers', ['name' => 'Beta Foods', 'phone' => '0801', 'email' => 'b@example.com'])->assertStatus(201)->assertJsonPath('name', 'Beta Foods');
        $this->send($buyer, '/api/v1/inventory/suppliers', ['name' => 'Beta Foods'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->withToken($buyer)->getJson('/api/v1/inventory/suppliers?q=beta')->assertOk()->assertJsonCount(1, 'items');
        $this->withToken($this->as('waiter', 'WAIT_STAFF'))->getJson('/api/v1/inventory/suppliers')->assertStatus(403);
    }
}
