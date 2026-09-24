<?php

namespace Tests\Feature\Payments;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\CollectionWorld;
use Tests\TestCase;

/**
 * Additive display fields for the cashier's inbox (order number, table label, waiter name, terminal label on `payment.collection`,
 * `waiterName` on handovers) and `GET /cash-in-hand?facilityId=` (docs/WAITER_COLLECTION.md sections 3 and 6).
 */
class CollectionDisplayFieldsTest extends TestCase
{
    use CollectionWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCollectionWorld(cashHolding: true);
    }

    private function tableOrder(string $label, string $total): string
    {
        $order = $this->billedOrder($total);
        $table = Ids::uuid7();
        DB::table('dining_table')->insert([
            'id' => Ids::toBinary($table), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($this->facility), 'label' => $label, 'status' => 'OCCUPIED',
        ]);
        DB::table('order')->where('id', Ids::toBinary($order))->update(['dining_table_id' => Ids::toBinary($table)]);

        return $order;
    }

    public function test_pending_list_carries_order_table_waiter_and_terminal_display_fields(): void
    {
        $order = $this->tableOrder('T7', '4000.0000');
        $terminal = Ids::uuid7();
        DB::table('payment_terminal')->insert([
            'id' => Ids::toBinary($terminal), 'organization_id' => Ids::toBinary($this->t['org']), 'site_id' => Ids::toBinary($this->t['site']),
            'facility_unit_id' => Ids::toBinary($this->facility), 'provider' => 'MANUAL_BANK', 'label' => 'GTB machine 2', 'serial' => 'SN-'.mt_rand(), 'status' => 'ACTIVE',
        ]);
        $id = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '4000.0000', 'terminalId' => $terminal, 'approvalCode' => 'AP-1', 'slipReference' => 'SL-1'])->assertCreated()->json('payment.id');

        $list = $this->getJson("/api/v1/payments?status=PENDING_CONFIRMATION&facilityId={$this->facility}", $this->auth($this->cashierToken, null))->assertOk();
        $item = collect($list->json('items'))->firstWhere('id', $id);

        $this->assertNotNull($item);
        $c = $item['collection'];
        $this->assertSame($order, $c['orderId']);
        $this->assertSame(DB::table('order')->where('id', Ids::toBinary($order))->value('order_number'), $c['orderNumber']);
        $this->assertSame('T7', $c['tableLabel']);
        $this->assertSame('Waiter1 Tester', $c['collectedByName']);
        $this->assertSame('GTB machine 2', $c['terminalLabel']);
        // the same block on the single-payment read
        $one = $this->getJson("/api/v1/payments/{$id}", $this->auth($this->cashierToken, null))->assertOk();
        $this->assertSame('T7', $one->json('collection.tableLabel'));
    }

    public function test_collection_without_table_or_terminal_has_null_display_fields(): void
    {
        $order = $this->billedOrder('1000.0000');
        $id = $this->collect($order, ['tenderType' => 'CARD_TERMINAL', 'amount' => '1000.0000', 'approvalCode' => 'AP-2'])->assertCreated()->json('payment.id');

        $c = $this->getJson("/api/v1/payments/{$id}", $this->auth($this->cashierToken, null))->assertOk()->json('collection');

        $this->assertNull($c['tableLabel']);
        $this->assertNull($c['terminalLabel']);
        $this->assertSame('Waiter1 Tester', $c['collectedByName']);
        $this->assertNotNull($c['orderNumber']);
    }

    public function test_cash_in_hand_list_shows_waiters_holding_cash_and_handovers_carry_the_name(): void
    {
        $this->collect($this->billedOrder('5000.0000'), ['tenderType' => 'CASH', 'amount' => '5000.0000'])->assertCreated();
        $this->collect($this->billedOrder('2000.0000'), ['tenderType' => 'CASH', 'amount' => '2000.0000'], $this->waiter2Token, $this->device2Token)->assertCreated();

        $list = $this->getJson("/api/v1/cash-in-hand?facilityId={$this->facility}", $this->auth($this->cashierToken, null))->assertOk();

        $this->assertSame(['5000.0000', '2000.0000'], array_column($list->json('items'), 'cashInHand'), 'largest holding first');
        $this->assertSame('Waiter1 Tester', $list->json('items.0.waiterName'));
        $this->assertSame($this->waiter->id, $list->json('items.0.staffId'));
        $this->assertSame(2, $list->json('items.1.pendingCollections') + 1);

        $h = $this->postJson('/api/v1/cash-handovers', ['declaredAmount' => '5000.0000'], $this->auth($this->waiterToken))->assertCreated();
        $this->assertSame('Waiter1 Tester', $h->json('waiterName'));
        $this->postJson("/api/v1/cash-handovers/{$h->json('id')}/receive", ['countedAmount' => '5000.0000'], $this->auth($this->cashierToken))->assertOk();

        $after = $this->getJson("/api/v1/cash-in-hand?facilityId={$this->facility}", $this->auth($this->cashierToken, null))->assertOk();
        $this->assertSame([$this->waiter2->id], array_column($after->json('items'), 'staffId'), 'a waiter who handed everything over is no longer listed');
    }

    public function test_cash_in_hand_list_needs_the_view_permission_and_a_facility(): void
    {
        $this->getJson("/api/v1/cash-in-hand?facilityId={$this->facility}", $this->auth($this->waiterToken, null))->assertStatus(403);
        $this->getJson('/api/v1/cash-in-hand', $this->auth($this->cashierToken, null))->assertStatus(422);
    }
}
