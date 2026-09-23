<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Contracts\InventoryConsumption;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\CountService;
use App\Domain\Inventory\Services\ReconciliationService;
use App\Domain\Inventory\Services\StockDocuments;
use App\Domain\Inventory\Support\ConsumptionLine;
use App\Domain\Inventory\Support\Qty;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InventoryData;
use Tests\Support\TestData;
use Tests\TestCase;

/**
 * Property-style: run long, seeded-random sequences of every stock operation and assert the invariants after EACH step:
 *   I-5  stock_balance == SUM(stock_movement) for every (item, location);
 *   no negative balance at a location that disallows it;
 *   a refused operation leaves NO trace (no movement / document / audit / outbox row);
 *   the balances equal an independent in-memory model of what the operations should have done.
 */
class InventoryInvariantTest extends TestCase
{
    /** @return list<array{0: int}> */
    public static function seeds(): array
    {
        return [[1], [2], [3], [4], [5], [1337]];
    }

    #[DataProvider('seeds')]
    public function test_balance_equals_sum_of_movements_after_random_operation_sequences(int $seed): void
    {
        mt_srand($seed);
        $t = TestData::tenant();
        $owner = TestData::staff($t, 'owner');
        TestData::assign($owner, 'OWNER', 'ORGANIZATION');
        $actor = $owner->id;

        $locs = [
            'main' => InventoryData::location($t, 'Main Store', 'MAIN_STORE', null, false, false),
            'bar' => InventoryData::location($t, 'Bar Store', 'BAR', TestData::facility($t, 'bar')->id),
            'kit' => InventoryData::location($t, 'Kitchen', 'KITCHEN', TestData::facility($t, 'kitchen')->id),
            'neg' => InventoryData::location($t, 'Overflow', 'FACILITY_STORE', TestData::facility($t, 'overflow')->id, true),
        ];
        $items = [];
        foreach (['A', 'B', 'C'] as $sku) {
            $items[$sku] = InventoryData::item($t, $sku, "Item {$sku}");
        }
        $locKeys = array_keys($locs);
        $itemKeys = array_keys($items);

        /** @var array<string, string> $model "loc|item" => qty */
        $model = [];
        $get = function (string $l, string $i) use (&$model) {
            return $model["$l|$i"] ?? '0.0000';
        };
        $add = function (string $l, string $i, string $d) use (&$model, $get) {
            $model["$l|$i"] = Qty::add($get($l, $i), $d);
        };
        $lines = []; // sale lines still consumed: [orderId, lineId, loc, item, qty]

        $docs = app(StockDocuments::class);
        $sales = app(InventoryConsumption::class);
        $adjust = app(AdjustmentService::class);
        $counts = app(CountService::class);
        $reconcile = app(ReconciliationService::class);
        $qty = fn () => (string) mt_rand(1, 9).(mt_rand(0, 1) ? '.'.mt_rand(1, 9999) : '');

        for ($step = 0; $step < 120; $step++) {
            $l = $locKeys[array_rand($locKeys)];
            $i = $itemKeys[array_rand($itemKeys)];
            $q = Qty::normalize($qty());
            $before = [DB::table('stock_movement')->count(), DB::table('audit_log')->count(), DB::table('outbox_event')->count()];
            $op = mt_rand(0, 8);
            $guarded = null; // [loc, item, qty] whose shortage is the ONLY legitimate reason for a refusal
            $wouldGoNegative = function (string $loc, string $item, string $need) use ($get) {
                return $loc !== 'neg' && Qty::cmp($get($loc, $item), $need) < 0;
            };

            try {
                switch ($op) {
                    case 0: // receipt
                        $docs->receive(['locationId' => $locs[$l]->id, 'lines' => [['itemId' => $items[$i]->id, 'quantity' => $q, 'unitCost' => '10']]], $actor);
                        $add($l, $i, $q);
                        break;
                    case 1: // transfer (2 lines)
                        $to = $locKeys[array_rand($locKeys)];
                        if ($to === $l) {
                            continue 2;
                        }
                        $j = $itemKeys[array_rand($itemKeys)];
                        if ($j === $i) {
                            $j = null;
                        }
                        $reqLines = [['itemId' => $items[$i]->id, 'quantity' => $q]];
                        $q2 = Qty::normalize($qty());
                        if ($j !== null) {
                            $reqLines[] = ['itemId' => $items[$j]->id, 'quantity' => $q2];
                        }
                        try {
                            $docs->transfer(['fromLocationId' => $locs[$l]->id, 'toLocationId' => $locs[$to]->id, 'lines' => $reqLines], $actor);
                        } catch (ApiProblem $e) {
                            $short = $wouldGoNegative($l, $i, $q) || ($j !== null && $wouldGoNegative($l, $j, $q2));
                            $this->assertTrue($short && $e->problemCode === 'insufficient_stock', "seed {$seed} step {$step}: unexpected {$e->problemCode}");
                            throw $e;
                        }
                        $add($l, $i, Qty::neg($q));
                        $add($to, $i, $q);
                        if ($j !== null) {
                            $add($l, $j, Qty::neg($q2));
                            $add($to, $j, $q2);
                        }
                        break;
                    case 2: // wastage
                        $guarded = [$l, $i, $q];
                        $docs->wastage(['locationId' => $locs[$l]->id, 'itemId' => $items[$i]->id, 'quantity' => $q, 'reason' => 'SPOILAGE'], $actor);
                        $add($l, $i, Qty::neg($q));
                        break;
                    case 3: // adjustment (owner posts directly), either sign
                        $delta = mt_rand(0, 1) ? $q : Qty::neg($q);
                        $adjust->request($locs[$l]->id, [['itemId' => $items[$i]->id, 'quantityDelta' => $delta]], 'CORRECTION', 'random adjustment', 'ADJUSTMENT', null, $actor);
                        $add($l, $i, $delta);
                        break;
                    case 4: // sale
                        $guarded = [$l, $i, $q];
                        $order = Ids::uuid7();
                        $line = Ids::uuid7();
                        $sales->consume($locs[$l]->facility_unit_id ?? TestData::facility($t, 'x'.$step)->id, [new ConsumptionLine($items[$i]->id, $q, $line, $locs[$l]->id)], 'order', $order, $actor);
                        $add($l, $i, Qty::neg($q));
                        $lines[] = [$order, $line, $l, $i, $q];
                        break;
                    case 5: // void a random earlier sale (once; replays are no-ops)
                        if ($lines === []) {
                            continue 2;
                        }
                        $k = array_rand($lines);
                        [$order, $line, $sl, $si, $sq] = $lines[$k];
                        $sales->reverse('order', $order, [$line], $actor);
                        $sales->reverse('order', $order, [$line], $actor); // idempotent replay
                        $add($sl, $si, $sq);
                        unset($lines[$k]);
                        break;
                    case 6: // customer return / supplier return
                        $kind = mt_rand(0, 1) ? 'CUSTOMER' : 'SUPPLIER';
                        $guarded = $kind === 'SUPPLIER' ? [$l, $i, $q] : null;
                        $docs->recordReturn(['locationId' => $locs[$l]->id, 'itemId' => $items[$i]->id, 'quantity' => $q, 'kind' => $kind], $actor);
                        $add($l, $i, $kind === 'CUSTOMER' ? $q : Qty::neg($q));
                        break;
                    case 7: // physical count of all items at a location (owner => posts every variance)
                        $counted = [];
                        foreach ($itemKeys as $ik) {
                            $counted[] = ['itemId' => $items[$ik]->id, 'countedQuantity' => Qty::normalize((string) mt_rand(0, 20))];
                        }
                        $c = $counts->create($locs[$l]->id, $counted, null, $actor);
                        $counts->post($c['id'], $actor);
                        foreach ($counted as $row) {
                            $ik = array_search($row['itemId'], array_map(fn ($x) => $x->id, $items), true);
                            $model["$l|$ik"] = $row['countedQuantity'];
                        }
                        $this->assertSame($row['countedQuantity'], InventoryData::onHand($locs[$l]->id, $row['itemId']));
                        break;
                    default: // duplicate consumption replay must be a no-op
                        if ($lines === []) {
                            continue 2;
                        }
                        [$order, $line, $sl, $si, $sq] = $lines[array_rand($lines)];
                        $sales->consume($locs[$sl]->facility_unit_id ?? TestData::facility($t, 'y'.$step)->id, [new ConsumptionLine($items[$si]->id, $sq, $line, $locs[$sl]->id)], 'order', $order, $actor);
                }
            } catch (ApiProblem $e) {
                $this->assertSame('insufficient_stock', $e->problemCode, "seed {$seed} step {$step} op {$op}");
                if ($guarded !== null) {
                    $this->assertTrue($wouldGoNegative(...$guarded), "seed {$seed} step {$step} op {$op}: refused although stock was sufficient");
                }
                // a refused operation leaves nothing behind (its savepoint rolled back)
                $this->assertSame($before, [DB::table('stock_movement')->count(), DB::table('audit_log')->count(), DB::table('outbox_event')->count()],
                    "seed {$seed} step {$step} op {$op}: refused operation left rows behind");
                // the DB transaction that wraps the test survives; nothing else to undo in the model
            }

            $this->assertSame([], $reconcile->drift(), "seed {$seed} step {$step} op {$op}: balance != SUM(movements)");
            foreach ($locs as $lk => $loc) {
                foreach ($items as $ik => $item) {
                    if ($lk !== 'neg') {
                        $this->assertFalse(Qty::isNegative(InventoryData::onHand($loc->id, $item->id)), "seed {$seed} step {$step}: negative stock at {$lk}/{$ik}");
                    }
                }
            }
        }

        // Independent model agrees with the database, and the guard held everywhere it must.
        foreach ($locs as $lk => $loc) {
            foreach ($items as $ik => $item) {
                $this->assertSame($get($lk, $ik), InventoryData::onHand($loc->id, $item->id), "seed {$seed}: model mismatch at {$lk}/{$ik}");
                $this->assertSame(InventoryData::onHand($loc->id, $item->id), Qty::normalize(InventoryData::ledgerSum($loc->id, $item->id)));
                if ($lk !== 'neg') {
                    $this->assertFalse(Qty::isNegative(InventoryData::onHand($loc->id, $item->id)), "seed {$seed}: negative stock at {$lk}");
                }
            }
        }
        $this->assertGreaterThan(20, DB::table('stock_movement')->count(), 'the sequence actually exercised the ledger');
    }
}
