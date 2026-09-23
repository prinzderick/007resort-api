<?php

namespace Tests\Feature\Customer;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\BookingHelpers;
use Tests\Support\Concurrent;
use Tests\Support\CustomerHelpers;
use Tests\Support\CustomerWorkers;

/** REAL concurrency: online customers and Reception staff compete for the same slot through the SAME engine. */
class CustomerConcurrencyTest extends ConcurrentTestCase
{
    use BookingHelpers, CustomerHelpers;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
    }

    /** @param list<array{status: int, code: ?string, id: ?string, source: ?string}> $raw */
    private function results(array $raw): array
    {
        foreach ($raw as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }

        return array_map(fn ($r) => $r['result'], $raw);
    }

    public function test_customer_and_staff_racing_for_the_last_slot_have_exactly_one_winner(): void
    {
        $r = $this->resource($this->w, ['online_bookable' => 1]);
        [, $staffToken] = $this->staffWith($this->w, 'front', self::BOOKING_PERMS);
        $customer = $this->newCustomer();
        for ($round = 0; $round < 4; $round++) {
            $slot = $this->slot(8 + $round);
            $res = $this->results(Concurrent::run(2, CustomerWorkers::class, 'holdAs', [[$staffToken, $customer['accessToken']], $r->id, $slot[0], $slot[1]]));
            $win = array_values(array_filter($res, fn ($x) => $x['status'] === 201));
            $lose = array_values(array_filter($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'));
            $this->assertCount(1, $win, json_encode($res));
            $this->assertCount(1, $lose, json_encode($res));
            $this->assertSame(1, DB::table('slot_allocation')->where('slot_start', $slot[0] === null ? '' : CarbonImmutable::parse($slot[0])->format('Y-m-d H:i:s.u'))->count());
        }
    }

    public function test_five_online_customers_racing_for_one_slot_have_one_winner(): void
    {
        $r = $this->resource($this->w, ['online_bookable' => 1]);
        $tokens = array_map(fn () => $this->newCustomer()['accessToken'], range(1, 5));
        $slot = $this->slot(11);
        $res = $this->results(Concurrent::run(5, CustomerWorkers::class, 'holdAs', [$tokens, $r->id, $slot[0], $slot[1]]));
        $this->assertCount(1, array_filter($res, fn ($x) => $x['status'] === 201), json_encode($res));
        $this->assertCount(4, array_filter($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'), json_encode($res));
        $this->assertSame(1, DB::table('booking')->where('source', 'ONLINE')->count());
        $this->assertSame(1, DB::table('booking')->whereNotNull('customer_id')->count());
    }

    public function test_a_replayed_idempotency_key_under_concurrency_creates_one_booking(): void
    {
        $r = $this->resource($this->w, ['online_bookable' => 1]);
        $c = $this->newCustomer();
        $slot = $this->slot(14);
        $res = $this->results(Concurrent::run(4, CustomerWorkers::class, 'holdSameKey', [$c['accessToken'], 'same-key-'.bin2hex(random_bytes(4)), $r->id, $slot[0], $slot[1]]));
        $ok = array_filter($res, fn ($x) => $x['status'] === 201);
        $this->assertGreaterThanOrEqual(1, count($ok), json_encode($res));
        $this->assertCount(1, array_unique(array_column($ok, 'id')), 'every replay returns the SAME booking');
        $this->assertSame(1, DB::table('booking')->count());
    }
}
