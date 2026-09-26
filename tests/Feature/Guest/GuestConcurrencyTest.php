<?php

namespace Tests\Feature\Guest;

use App\Domain\Customer\Services\ServiceTokenService;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrentTestCase;
use Tests\Support\BookingHelpers;
use Tests\Support\Concurrent;
use Tests\Support\CustomerHelpers;
use Tests\Support\GuestWorkers;

/** REAL concurrency (separate PHP processes, real MySQL): guests, account holders and staff compete through the SAME engine. */
class GuestConcurrencyTest extends ConcurrentTestCase
{
    use BookingHelpers, CustomerHelpers;

    private array $w;

    private string $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        $this->svc = 'r7s_conc_'.bin2hex(random_bytes(6));
        app(ServiceTokenService::class)->create('guest-race', $this->w['t']['org'], null, $this->svc, 'public.read,public.checkout');
    }

    /** @return list<array<string, mixed>> */
    private function results(array $raw): array
    {
        foreach ($raw as $r) {
            $this->assertNull($r['error'], (string) $r['error']);
        }

        return array_map(fn ($r) => $r['result'], $raw);
    }

    public function test_guest_account_and_staff_racing_for_the_last_slot_have_exactly_one_winner(): void
    {
        $r = $this->resource($this->w, ['online_bookable' => 1]);
        [, $staffToken] = $this->staffWith($this->w, 'front', self::BOOKING_PERMS);
        $customer = $this->newCustomer();
        for ($round = 0; $round < 3; $round++) {
            $slot = $this->slot(8 + $round);
            $actors = [['guest', $this->svc, "g1-{$round}@example.test"], ['customer', $customer['accessToken']], ['staff', $staffToken], ['guest', $this->svc, "g2-{$round}@example.test"]];
            $res = $this->results(Concurrent::run(4, GuestWorkers::class, 'holdAs', [$actors, $r->id, $slot[0], $slot[1]]));
            $win = array_values(array_filter($res, fn ($x) => $x['status'] === 201));
            $lose = array_values(array_filter($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'));
            $this->assertCount(1, $win, json_encode($res));
            $this->assertCount(3, $lose, json_encode($res));
        }
        $this->assertSame(3, DB::table('slot_allocation')->count());
    }

    public function test_six_guests_racing_for_one_slot_have_one_winner_and_leave_no_orphans(): void
    {
        $r = $this->resource($this->w, ['online_bookable' => 1]);
        $slot = $this->slot(11);
        $actors = array_map(fn ($i) => ['guest', $this->svc, "racer{$i}@example.test"], range(0, 5));
        $res = $this->results(Concurrent::run(6, GuestWorkers::class, 'holdAs', [$actors, $r->id, $slot[0], $slot[1]]));
        $this->assertCount(1, array_filter($res, fn ($x) => $x['status'] === 201), json_encode($res));
        $this->assertCount(5, array_filter($res, fn ($x) => $x['status'] === 409 && $x['code'] === 'slot_unavailable'), json_encode($res));
        $this->assertSame(1, DB::table('booking')->count());
        $this->assertSame(1, DB::table('guest_order')->count(), 'losers created no guest order');
        $this->assertSame(0, DB::table('customer')->count() + DB::table('customer_account')->count());
    }

    public function test_the_active_hold_cap_holds_under_a_race_for_one_contact(): void
    {
        config(['guest.max_active_holds' => 2]);
        putenv('GUEST_MAX_ACTIVE_HOLDS=2'); // inherited by the child processes
        $_ENV['GUEST_MAX_ACTIVE_HOLDS'] = $_SERVER['GUEST_MAX_ACTIVE_HOLDS'] = '2';
        $this->beforeApplicationDestroyed(function (): void {
            putenv('GUEST_MAX_ACTIVE_HOLDS');
            unset($_ENV['GUEST_MAX_ACTIVE_HOLDS'], $_SERVER['GUEST_MAX_ACTIVE_HOLDS']);
        });
        $ids = array_map(fn () => $this->resource($this->w, ['online_bookable' => 1])->id, range(1, 5));
        $slot = $this->slot(15);
        $res = $this->results(Concurrent::run(5, GuestWorkers::class, 'holdSameContact', [$this->svc, 'one.person@example.test', $ids, $slot[0], $slot[1]]));
        $created = array_filter($res, fn ($x) => $x['status'] === 201);
        $this->assertCount(2, $created, json_encode($res));
        $this->assertSame(2, DB::table('booking')->count());
        foreach (array_filter($res, fn ($x) => $x['status'] !== 201) as $x) {
            $this->assertContains($x['code'], ['too_many_active_holds', 'concurrency_conflict'], json_encode($x));
        }
    }

    public function test_a_replayed_idempotency_key_creates_one_guest_order(): void
    {
        $r = $this->resource($this->w, ['online_bookable' => 1]);
        $slot = $this->slot(14);
        $res = $this->results(Concurrent::run(4, GuestWorkers::class, 'holdSameKey', [$this->svc, 'same@example.test', 'same-key-'.bin2hex(random_bytes(4)), $r->id, $slot[0], $slot[1]]));
        $ok = array_filter($res, fn ($x) => $x['status'] === 201);
        $this->assertGreaterThanOrEqual(1, count($ok), json_encode($res));
        $this->assertCount(1, array_unique(array_column($ok, 'id')));
        $this->assertCount(1, array_unique(array_column($ok, 'ref')));
        $this->assertSame(1, DB::table('booking')->count());
        $this->assertSame(1, DB::table('guest_order')->count());
    }
}
