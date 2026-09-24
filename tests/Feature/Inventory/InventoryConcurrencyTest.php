<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\ReconciliationService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\Concurrent;
use Tests\Support\InventoryData;
use Tests\Support\InventoryWorkers;
use Tests\Support\TestData;

/** Real concurrency: N PHP processes, N MySQL connections, released at the same instant. */
class InventoryConcurrencyTest extends ConcurrentTestCase
{
    private array $t;

    private $bar;

    private $main;

    private $barFacility;

    private $beer;

    private $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = TestData::tenant();
        $this->barFacility = TestData::facility($this->t, 'bar');
        $this->bar = InventoryData::location($this->t, 'Bar Store', 'BAR', $this->barFacility->id);
        $this->main = InventoryData::location($this->t, 'Main Store', 'MAIN_STORE', null, false, false);
        $this->beer = InventoryData::item($this->t, 'BEER', 'Beer');
        $this->wine = InventoryData::item($this->t, 'WINE', 'Wine');
    }

    private function assertInvariant(): void
    {
        $this->assertSame([], app(ReconciliationService::class)->drift(), 'stock_balance must equal SUM(stock_movement)');
    }

    private function codes(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $x = $r['result'];
            $out[] = ($x['ok'] ?? false) ? 'OK' : ($x['code'] ?? 'UNKNOWN').' '.($x['message'] ?? '');
        }

        return $out;
    }

    public function test_n_concurrent_workers_selling_the_last_unit_exactly_one_succeeds(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '1');

        $codes = $this->codes(Concurrent::run(10, InventoryWorkers::class, 'sell', [$this->barFacility->id, $this->beer->id, '1']));

        $this->assertSame(1, count(array_keys($codes, 'OK')), 'exactly one sale wins the last unit: '.json_encode($codes));
        foreach ($codes as $c) {
            $this->assertTrue($c === 'OK' || str_starts_with($c, 'insufficient_stock'), "loser must fail cleanly with insufficient_stock, got: {$c}");
        }
        $this->assertSame('0.0000', InventoryData::onHand($this->bar->id, $this->beer->id), 'never negative');
        $this->assertSame(1, DB::table('stock_movement')->where('reason', 'SALE')->count());
        $this->assertInvariant();
    }

    public function test_concurrent_sales_never_oversell_a_larger_stock(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '7');

        $codes = $this->codes(Concurrent::run(16, InventoryWorkers::class, 'sell', [$this->barFacility->id, $this->beer->id, '1']));

        $this->assertSame(7, count(array_keys($codes, 'OK')), json_encode($codes));
        $this->assertSame(9, count(array_filter($codes, fn ($c) => str_starts_with($c, 'insufficient_stock'))));
        $this->assertSame('0.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertInvariant();
    }

    public function test_racing_replays_of_the_same_order_line_deduct_once(): void
    {
        InventoryData::stock($this->bar->id, $this->beer->id, '10');
        $order = Ids::uuid7();
        $line = Ids::uuid7();

        $codes = $this->codes(Concurrent::run(8, InventoryWorkers::class, 'sellSameLine', [$this->barFacility->id, $this->beer->id, '3', $order, $line]));

        $this->assertSame(array_fill(0, 8, 'OK'), $codes, 'every replay is a success (idempotent), none an error');
        $this->assertSame('7.0000', InventoryData::onHand($this->bar->id, $this->beer->id), 'deducted exactly once');
        $this->assertSame(1, DB::table('stock_movement')->where('reason', 'SALE')->count());
        $this->assertInvariant();
    }

    public function test_concurrent_transfers_out_of_a_store_cannot_overdraw_it(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '5');

        $codes = $this->codes(Concurrent::run(9, InventoryWorkers::class, 'transferService', [$this->main->id, $this->bar->id, [['itemId' => $this->beer->id, 'quantity' => '1']]]));

        $this->assertSame(5, count(array_keys($codes, 'OK')), json_encode($codes));
        $this->assertSame('0.0000', InventoryData::onHand($this->main->id, $this->beer->id));
        $this->assertSame('5.0000', InventoryData::onHand($this->bar->id, $this->beer->id));
        $this->assertSame(5, DB::table('stock_transfer')->count(), 'failed transfers leave no document');
        $this->assertSame(10, DB::table('stock_movement')->where('reason', 'LIKE', 'TRANSFER_%')->count(), 'paired legs only, never a lone OUT or IN');
        $this->assertInvariant();
    }

    public function test_opposing_multi_line_transfers_do_not_deadlock(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '100');
        InventoryData::stock($this->main->id, $this->wine->id, '100');
        InventoryData::stock($this->bar->id, $this->beer->id, '100');
        InventoryData::stock($this->bar->id, $this->wine->id, '100');
        $forward = [['itemId' => $this->beer->id, 'quantity' => '1'], ['itemId' => $this->wine->id, 'quantity' => '1']];
        $backward = [['itemId' => $this->wine->id, 'quantity' => '1'], ['itemId' => $this->beer->id, 'quantity' => '1']];

        // half the workers push main->bar (beer,wine), half push bar->main (wine,beer): the classic lock-order inversion.
        $a = Concurrent::run(6, InventoryWorkers::class, 'transferService', [$this->main->id, $this->bar->id, $forward]);
        $b = Concurrent::run(6, InventoryWorkers::class, 'transferService', [$this->bar->id, $this->main->id, $backward]);
        foreach (array_merge($this->codes($a), $this->codes($b)) as $c) {
            $this->assertSame('OK', $c);
        }
        $this->assertInvariant();
        $this->assertSame(12, DB::table('stock_transfer')->count());
    }

    public function test_concurrent_http_transfers_through_the_full_stack_are_all_or_nothing(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '3');
        $keeper = TestData::staff($this->t, 'keeper');
        TestData::assign($keeper, 'STOREKEEPER', 'SITE');
        $token = $this->postJson('/api/v1/auth/staff/login', ['username' => 'keeper', 'password' => TestData::PASSWORD])->json('accessToken');

        $results = Concurrent::run(6, InventoryWorkers::class, 'transferHttp', [$token, $this->main->id, $this->bar->id, [['itemId' => $this->beer->id, 'quantity' => '1']]]);

        $statuses = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $statuses[] = $r['result']['status'].($r['result']['code'] ? ':'.$r['result']['code'] : '');
        }
        $this->assertSame(3, count(array_keys($statuses, '201')), json_encode($statuses));
        $this->assertSame(3, count(array_keys($statuses, '409:insufficient_stock')), json_encode($statuses));
        $this->assertSame('0.0000', InventoryData::onHand($this->main->id, $this->beer->id));
        $this->assertSame(3, DB::table('stock_transfer')->count());
        $this->assertInvariant();
    }

    public function test_same_idempotency_key_racing_over_http_transfers_once(): void
    {
        InventoryData::stock($this->main->id, $this->beer->id, '10');
        $keeper = TestData::staff($this->t, 'keeper');
        TestData::assign($keeper, 'STOREKEEPER', 'SITE');
        $token = $this->postJson('/api/v1/auth/staff/login', ['username' => 'keeper', 'password' => TestData::PASSWORD])->json('accessToken');

        $results = Concurrent::run(6, InventoryWorkers::class, 'transferHttp', [$token, $this->main->id, $this->bar->id, [['itemId' => $this->beer->id, 'quantity' => '2']], 'same-key-1']);

        $ids = [];
        foreach ($results as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
            $this->assertSame(201, $r['result']['status']);
            $ids[] = $r['result']['id'];
        }
        $this->assertCount(1, array_unique($ids));
        $this->assertSame('8.0000', InventoryData::onHand($this->main->id, $this->beer->id), 'moved exactly once');
        $this->assertSame(1, DB::table('stock_transfer')->count());
        $this->assertInvariant();
    }

    public function test_a_rental_asset_can_be_issued_to_only_one_customer(): void
    {
        $id = Ids::uuid7();
        DB::table('rental_asset')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($this->t['org']), 'item_id' => Ids::toBinary($this->beer->id),
            'location_id' => Ids::toBinary($this->bar->id), 'asset_tag' => 'RKT-01',
        ]);

        $codes = $this->codes(Concurrent::run(6, InventoryWorkers::class, 'issueAsset', ['RKT-01']));

        $this->assertSame(1, count(array_keys($codes, 'OK')), json_encode($codes));
        $this->assertSame(5, count(array_filter($codes, fn ($c) => str_starts_with($c, 'concurrency_conflict'))));
        $this->assertSame('ISSUED', DB::table('rental_asset')->value('status'));
    }
}
