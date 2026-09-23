<?php

namespace Tests\Feature\Booking;

use App\Domain\Ticketing\Services\EntitlementService;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\BookingHelpers;
use Tests\Support\BookingWorkers;
use Tests\Support\Concurrent;

/**
 * REAL concurrency against real MySQL: N PHP processes, each with its own connection, released at the same instant.
 * These prove the scarce-resource guards: slot_allocation UNIQUE, conditional qty UPDATEs, row-locked confirm.
 */
class ConcurrencyTest extends ConcurrentTestCase
{
    use BookingHelpers;

    private array $w;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        [, $this->token] = $this->staffWith($this->w, 'racer', array_unique([...self::BOOKING_PERMS, ...self::SCAN_PERMS]));
    }

    /** @return list<array{status: int, code: ?string, result: ?string}> */
    private function results(array $raw): array
    {
        foreach ($raw as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }

        return array_map(fn ($r) => $r['result'], $raw);
    }

    private function tally(array $results, callable $pred): int
    {
        return count(array_filter($results, $pred));
    }

    public function test_two_simultaneous_holds_for_the_last_slot_exactly_one_wins(): void
    {
        $r = $this->resource($this->w);
        for ($round = 0; $round < 3; $round++) {
            $slot = $this->slot(9 + $round);
            $res = $this->results(Concurrent::run(2, BookingWorkers::class, 'hold', [$this->token, $r->id, $slot[0], $slot[1]]));
            $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 201), 'exactly one 201 in round '.$round.': '.json_encode($res));
            $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'), 'the loser gets 409 slot_unavailable: '.json_encode($res));
        }
        $this->assertSame(3, DB::table('slot_allocation')->count());
        $this->assertSame(3, DB::table('booking')->where('status', 'HELD')->count()); // the loser's booking rolled back completely
    }

    public function test_eight_simultaneous_holds_for_one_slot_exactly_one_wins(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $res = $this->results(Concurrent::run(8, BookingWorkers::class, 'hold', [$this->token, $r->id, $slot[0], $slot[1]]));
        $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 201), json_encode($res));
        $this->assertSame(7, $this->tally($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'), json_encode($res));
        $this->assertSame(1, DB::table('slot_allocation')->count());
        $this->assertSame(1, DB::table('booking')->count());
        $this->assertSame(1, DB::table('booking_item')->count());
    }

    public function test_per_unit_capacity_never_oversells(): void
    {
        $r = $this->resource($this->w, ['mode' => 'INDIVIDUAL_CAPACITY', 'capacity' => 3]);
        $slot = $this->slot();
        $res = $this->results(Concurrent::run(8, BookingWorkers::class, 'hold', [$this->token, $r->id, $slot[0], $slot[1], 1]));
        $this->assertSame(3, $this->tally($res, fn ($x) => $x['status'] === 201), json_encode($res));
        $this->assertSame(5, $this->tally($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'), json_encode($res));
        $this->assertSame(0, $this->tally($res, fn ($x) => $x['status'] >= 500));
        $units = DB::table('slot_allocation')->pluck('unit_no')->map(fn ($u) => (int) $u)->sort()->values()->all();
        $this->assertSame([1, 2, 3], $units, 'each winner got a distinct unit');
    }

    public function test_racing_for_a_stale_expired_hold_yields_exactly_one_new_holder(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $old = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1]], $this->idem($this->token))->assertStatus(201)->json();
        $past = now('UTC')->subMinute()->format('Y-m-d H:i:s.u');
        DB::table('booking')->where('id', Ids::toBinary($old['id']))->update(['hold_expires_at' => $past]);
        DB::table('slot_allocation')->update(['hold_expires_at' => $past]);

        $res = $this->results(Concurrent::run(6, BookingWorkers::class, 'hold', [$this->token, $r->id, $slot[0], $slot[1]]));
        $this->assertSame(0, $this->tally($res, fn ($x) => $x['status'] >= 500), json_encode($res));
        $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 201), json_encode($res));
        $this->assertSame('EXPIRED', DB::table('booking')->where('id', Ids::toBinary($old['id']))->value('status'));
        $this->assertSame(1, DB::table('slot_allocation')->count());
    }

    public function test_concurrent_confirms_of_one_hold_pay_exactly_once(): void
    {
        $r = $this->resource($this->w);
        $slot = $this->slot();
        $held = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1]], $this->idem($this->token))->assertStatus(201)->json();
        $res = $this->results(Concurrent::run(4, BookingWorkers::class, 'confirm', [$this->token, $held['id'], $held['rowVersion'], '5000.0000']));
        $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 200), json_encode($res));
        $this->assertSame(0, $this->tally($res, fn ($x) => $x['status'] >= 500), json_encode($res));
        $this->assertSame(1, DB::table('entitlement')->count()); // one entitlement, never two
        $this->assertSame('CONFIRMED', DB::table('booking')->value('status'));
    }

    public function test_two_scanners_redeeming_the_same_single_use_ticket_exactly_one_is_valid(): void
    {
        $ent = $this->ticket([$this->access()]);
        for ($round = 0; $round < 1; $round++) {
            $res = $this->results(Concurrent::run(2, BookingWorkers::class, 'redeem', [$this->token, $ent->qr_token, $this->w['pool']->id]));
            $this->assertSame(200, $res[0]['status']);
            $this->assertSame(200, $res[1]['status']); // scan outcomes are always HTTP 200
            $out = array_column($res, 'result');
            sort($out);
            $this->assertSame(['USED', 'VALID'], $out);
        }
        $this->assertSame(1, DB::table('redemption')->count());
        $this->assertSame(2, DB::table('validation_event')->count());
        $this->assertEquals(1, DB::table('entitlement_item')->value('qty_redeemed'));
        $this->assertTrue(Audit::verifyChain()->valid);
    }

    public function test_eight_scanners_one_ticket_and_a_multi_entry_pass_never_overdraws(): void
    {
        $single = $this->ticket([$this->access()]);
        $res = $this->results(Concurrent::run(8, BookingWorkers::class, 'redeem', [$this->token, $single->qr_token, $this->w['pool']->id]));
        $out = array_count_values(array_column($res, 'result'));
        $this->assertSame(['VALID' => 1, 'USED' => 7], [
            'VALID' => $out['VALID'] ?? 0, 'USED' => $out['USED'] ?? 0,
        ]);

        $multi = $this->ticket([$this->access(['qty' => 3, 'validationMode' => 'MULTIPLE_ENTRY'])]);
        $res = $this->results(Concurrent::run(8, BookingWorkers::class, 'redeem', [$this->token, $multi->qr_token, $this->w['pool']->id]));
        $out = array_count_values(array_column($res, 'result'));
        $this->assertSame(3, $out['VALID'] ?? 0, json_encode($res));
        $this->assertSame(5, $out['USED'] ?? 0);
        $item = DB::table('entitlement_item')->where('entitlement_id', Ids::toBinary($multi->id))->first();
        $this->assertEquals(3, $item->qty_redeemed);
        $this->assertSame(3, DB::table('redemption')->where('entitlement_item_id', $item->id)->count());
    }

    public function test_two_staff_releasing_the_same_rental_exactly_one_succeeds(): void
    {
        $ent = $this->ticket([['kind' => 'RENTAL', 'name' => 'Racket x2', 'qty' => 2, 'facilityUnitId' => $this->w['store']->id]]);
        $item = $ent->items[0];
        $res = $this->results(Concurrent::run(2, BookingWorkers::class, 'release', [$this->token, $ent->id, $item->id]));
        $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 200), json_encode($res));
        $this->assertSame(1, $this->tally($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'ticket_used'), json_encode($res));
        $this->assertSame(1, DB::table('redemption')->where('action', 'RELEASE')->count());
        $this->assertEquals(2, DB::table('entitlement_item')->value('qty_redeemed'));
        $this->assertSame(1, DB::table('outbox_event')->where('event_type', 'RentalReleased')->count());

        $res = $this->results(Concurrent::run(6, BookingWorkers::class, 'release', [$this->token, $ent->id, $item->id]));
        $this->assertSame(0, $this->tally($res, fn ($x) => $x['status'] === 200)); // already released: nobody can release it again
    }

    private function ticket(array $items)
    {
        return DB::transaction(fn () => app(EntitlementService::class)->issue('c:'.Ids::uuid7(), $this->w['t']['org'], $this->w['t']['site'], $items, holderName: 'Racer'));
    }

    private function access(array $o = []): array
    {
        return $o + ['kind' => 'ACCESS', 'name' => 'Pool - Adult', 'qty' => 1, 'facilityUnitId' => $this->w['pool']->id, 'validationMode' => 'SINGLE_USE',
            'validFrom' => now('UTC')->subHour(), 'validUntil' => now('UTC')->addHours(5)];
    }
}
