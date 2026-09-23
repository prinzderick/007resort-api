<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Contracts\CloudBookingAuthority;
use App\Domain\Booking\Contracts\CloudUnreachableException;
use App\Domain\Booking\Contracts\ConnectivityProbe;
use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Services\BookingAuthorityPolicy;
use App\Domain\Booking\Support\HoldCommand;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingHelpers;
use Tests\TestCase;

/** Booking Authority policy (strategies A/B/C, Cloud pool vs Local reserve, delegation) — architecture/sync/booking-authority-and-offline-allocation.md */
class BookingAuthorityTest extends TestCase
{
    use BookingHelpers;

    private array $w;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        [, $this->token] = $this->staffWith($this->w, 'auth', self::BOOKING_PERMS);
    }

    private function twoNode(string $node = 'local', bool $cloudReachable = false, ?CloudBookingAuthority $cloud = null, ?int $heartbeatAge = 0): void
    {
        config(['booking.cloud_enabled' => true, 'node.node' => $node, 'node.is_local' => $node === 'local', 'node.is_cloud' => $node === 'cloud']);
        $this->app->instance(ConnectivityProbe::class, new class($cloudReachable, $heartbeatAge) implements ConnectivityProbe
        {
            public function __construct(private bool $up, private ?int $age) {}

            public function cloudReachable(): bool
            {
                return $this->up;
            }

            public function localHeartbeatAgeSeconds(): ?int
            {
                return $this->age;
            }
        });
        $this->app->instance(CloudBookingAuthority::class, $cloud ?? new \App\Domain\Booking\Services\NullCloudBookingAuthority);
        $this->app->forgetInstance(BookingAuthorityPolicy::class);
        $this->app->forgetInstance(\App\Domain\Booking\Services\BookingService::class);
    }

    private function res(string $strategy, int $capacity = 5, int $reserve = 2): BookableResource
    {
        return $this->resource($this->w, ['mode' => 'INDIVIDUAL_CAPACITY', 'capacity' => $capacity, 'local_reserve_units' => $reserve, 'offline_strategy' => $strategy, 'allow_whole_resource' => true, 'price' => '1000.0000']);
    }

    private function cmd(BookableResource $r, string $channel = 'STAFF', bool $whole = false): HoldCommand
    {
        $s = CarbonImmutable::parse($this->slot()[0]);

        return new HoldCommand($r->id, $s, $s->addHour(), 1, $whole, null, $channel);
    }

    private function plan(BookableResource $r, string $channel = 'STAFF', bool $whole = false)
    {
        return app(BookingAuthorityPolicy::class)->plan($r->fresh(), $this->cmd($r, $channel, $whole));
    }

    private function hold(BookableResource $r, string $hour = '10', int $qty = 1)
    {
        $slot = $this->slot((int) $hour);

        return $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1], 'quantity' => $qty], $this->idem($this->token));
    }

    public function test_single_node_mvp_is_sole_authority_with_all_units(): void
    {
        $r = $this->res('A_OFFLINE_ALLOCATION');
        $p = $this->plan($r);
        $this->assertSame(['CLOUD', 1, 5, false], [$p->pool, $p->unitLo, $p->unitHi, $p->delegateToCloud]);
    }

    public function test_local_offline_strategy_a_commits_only_from_the_local_reserve(): void
    {
        $this->twoNode('local', cloudReachable: false);
        $r = $this->res('A_OFFLINE_ALLOCATION', 5, 2);
        $p = $this->plan($r);
        $this->assertSame(['LOCAL', 4, 5], [$p->pool, $p->unitLo, $p->unitHi]);

        // end to end: offline holds land on units 4 and 5 only, the third is refused
        $this->hold($r)->assertStatus(201);
        $this->hold($r)->assertStatus(201);
        $this->assertEqualsCanonicalizing([4, 5], DB::table('slot_allocation')->pluck('unit_no')->map(fn ($u) => (int) $u)->all());
        $this->assertSame(['LOCAL'], DB::table('slot_allocation')->pluck('pool')->unique()->values()->all());
        $this->hold($r)->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
        $this->assertSame('LOCAL', DB::table('booking')->value('allocation_pool'));
    }

    public function test_local_offline_strategy_b_and_no_reserve_require_connectivity_for_that_resource_only(): void
    {
        $this->twoNode('local', cloudReachable: false);
        $b = $this->res('B_ONLINE_AUTHORITY_REQUIRED');
        $this->hold($b)->assertStatus(409)->assertJsonPath('code', 'offline_not_allowed')->assertJsonPath('meta.strategy', 'B');
        $noReserve = $this->res('A_OFFLINE_ALLOCATION', 5, 0);
        $this->hold($noReserve)->assertStatus(409)->assertJsonPath('code', 'offline_not_allowed');
        // an unrelated resource with a reserve keeps working
        $this->hold($this->res('A_OFFLINE_ALLOCATION', 5, 2))->assertStatus(201);
        // and unrelated local operations are untouched (availability queries still answer offline)
        $slot = $this->slot();
        $this->getJson("/api/v1/bookings/resources/{$b->id}/availability?from={$slot[0]}&to={$slot[1]}", $this->idem($this->token))->assertOk();
    }

    public function test_local_offline_whole_resource_needs_the_full_reserve(): void
    {
        $this->twoNode('local', cloudReachable: false);
        $partial = $this->res('A_OFFLINE_ALLOCATION', 5, 2);
        try {
            $this->plan($partial, 'STAFF', true);
            $this->fail('whole-resource from a partial reserve must be refused');
        } catch (ApiProblem $e) {
            $this->assertSame('offline_not_allowed', $e->problemCode);
        }
        $full = $this->res('A_OFFLINE_ALLOCATION', 3, 3);
        $p = $this->plan($full, 'STAFF', true);
        $this->assertSame(['LOCAL', 1, 3], [$p->pool, $p->unitLo, $p->unitHi]);
    }

    public function test_strategy_c_behaves_like_a_locally(): void
    {
        $this->twoNode('local', cloudReachable: false);
        $r = $this->res('C_DISABLE_ONLINE', 4, 1);
        $p = $this->plan($r);
        $this->assertSame(['LOCAL', 4, 4], [$p->pool, $p->unitLo, $p->unitHi]);
    }

    public function test_local_delegates_to_cloud_authority_when_reachable_and_mirrors_the_booking(): void
    {
        $r = $this->res('A_OFFLINE_ALLOCATION', 5, 2);
        $slot = $this->slot();
        $cloud = new class($r, $slot, $this->w) implements CloudBookingAuthority
        {
            public int $calls = 0;

            public function __construct(private BookableResource $r, private array $slot, private array $w) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function hold(HoldCommand $command): array
            {
                $this->calls++;

                return [
                    'bookingId' => Ids::uuid7(), 'number' => 'BK-99999999-C0001', 'organizationId' => $this->w['t']['org'], 'siteId' => $this->w['t']['site'], 'facilityId' => $this->r->facility_unit_id,
                    'resourceId' => $this->r->id, 'itemId' => Ids::uuid7(), 'status' => 'HELD', 'source' => 'STAFF', 'originNode' => 'CLOUD', 'pool' => 'CLOUD',
                    'start' => $this->slot[0], 'end' => $this->slot[1], 'quantity' => 1, 'wholeResource' => false, 'unitNos' => [1], 'customer' => ['name' => 'Cloud Customer'],
                    'currency' => 'NGN', 'total' => '1000.0000', 'amountPaid' => '0.0000', 'holdExpiresAt' => CarbonImmutable::now('UTC')->addMinutes(10)->format('Y-m-d\TH:i:s\Z'),
                    'orderId' => null, 'entitlementId' => null, 'rowVersion' => 1,
                ];
            }
        };
        $this->twoNode('local', cloudReachable: true, cloud: $cloud);

        $body = $this->hold($r)->assertStatus(201)->assertJsonPath('number', 'BK-99999999-C0001')->assertJsonPath('customer.name', 'Cloud Customer')->json();
        $this->assertSame(1, $cloud->calls);
        $this->assertSame('CLOUD', DB::table('booking')->where('id', Ids::toBinary($body['id']))->value('allocation_pool'));
        $this->assertSame(1, DB::table('slot_allocation')->count());
        // redelivery of the same snapshot is a no-op (idempotent inbox)
        $snap = app(\App\Domain\Booking\Services\BookingSyncApplier::class)->snapshot(\App\Domain\Booking\Models\Booking::query()->findOrFail($body['id']));
        app(\App\Domain\Booking\Services\BookingSyncApplier::class)->applySnapshot($snap);
        $this->assertSame(1, DB::table('booking')->count());
        // cancelling from Cloud releases the local slot; a snapshot for an occupied unit is a conflict
        app(\App\Domain\Booking\Services\BookingSyncApplier::class)->applyCancelled($body['id'], 'cloud cancel');
        $this->assertSame(0, DB::table('slot_allocation')->count());
        $this->assertSame('CANCELLED', DB::table('booking')->where('id', Ids::toBinary($body['id']))->value('status'));
    }

    public function test_cloud_unreachable_call_falls_back_to_the_offline_strategy(): void
    {
        $down = new class implements CloudBookingAuthority
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function hold(HoldCommand $command): array
            {
                throw new CloudUnreachableException('timeout');
            }
        };
        $this->twoNode('local', cloudReachable: true, cloud: $down);
        $r = $this->res('A_OFFLINE_ALLOCATION', 5, 2);
        $this->hold($r)->assertStatus(201);
        $this->assertSame([4], DB::table('slot_allocation')->pluck('unit_no')->map(fn ($u) => (int) $u)->all()); // fell back to the LOCAL reserve (units 4-5)
        $b = $this->res('B_ONLINE_AUTHORITY_REQUIRED');
        $this->hold($b)->assertStatus(409)->assertJsonPath('code', 'offline_not_allowed');
    }

    public function test_cloud_node_gives_the_website_only_the_cloud_pool_and_staff_the_full_range(): void
    {
        $this->twoNode('cloud');
        $r = $this->res('A_OFFLINE_ALLOCATION', 5, 2);
        $web = $this->plan($r, 'ONLINE');
        $this->assertSame(['CLOUD', 1, 3], [$web->pool, $web->unitLo, $web->unitHi]);
        $staff = $this->plan($r, 'STAFF');
        $this->assertSame([1, 5], [$staff->unitLo, $staff->unitHi]);
        $this->expectException(ApiProblem::class);
        $this->plan($this->res('A_OFFLINE_ALLOCATION', 5, 5), 'ONLINE'); // whole capacity is the offline reserve: nothing for the website
    }

    public function test_cloud_pool_and_local_reserve_can_never_double_book_the_same_unit(): void
    {
        // Same capacity-3 resource, reserve 1: the website can take units 1-2 (cloud), the offline Reception unit 3.
        $r = $this->res('A_OFFLINE_ALLOCATION', 3, 1);
        $slot = $this->slot();
        $this->twoNode('cloud');
        $cloudPolicy = app(BookingAuthorityPolicy::class);
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(1, $cloudPolicy->plan($r->fresh(), new HoldCommand($r->id, CarbonImmutable::parse($slot[0]), CarbonImmutable::parse($slot[1]), 1, false, null, 'ONLINE'))->unitLo);
            app(\App\Domain\Booking\Services\BookingService::class)->hold(new HoldCommand($r->id, CarbonImmutable::parse($slot[0]), CarbonImmutable::parse($slot[1]), 1, false, null, 'ONLINE', $this->staffIdFor()));
        }
        try {
            app(\App\Domain\Booking\Services\BookingService::class)->hold(new HoldCommand($r->id, CarbonImmutable::parse($slot[0]), CarbonImmutable::parse($slot[1]), 1, false, null, 'ONLINE', $this->staffIdFor()));
            $this->fail('third website booking must not get the reserve unit');
        } catch (ApiProblem $e) {
            $this->assertSame('slot_unavailable', $e->problemCode);
        }
        $this->assertEqualsCanonicalizing([1, 2], DB::table('slot_allocation')->pluck('unit_no')->map(fn ($u) => (int) $u)->all());

        $this->twoNode('local', cloudReachable: false); // outage: Reception books from the reserve
        $this->hold($r)->assertStatus(201);
        $this->assertEqualsCanonicalizing([1, 2, 3], DB::table('slot_allocation')->pluck('unit_no')->map(fn ($u) => (int) $u)->all());
        $this->hold($r)->assertStatus(409);
    }

    private function staffIdFor(): ?string
    {
        return null;
    }

    public function test_strategy_c_disables_online_booking_when_the_local_heartbeat_is_stale(): void
    {
        $r = $this->res('C_DISABLE_ONLINE', 4, 1);
        $this->twoNode('cloud', heartbeatAge: 60);
        $this->assertSame('CLOUD', $this->plan($r, 'ONLINE')->pool);
        $this->twoNode('cloud', heartbeatAge: 100000);
        try {
            $this->plan($r, 'ONLINE');
            $this->fail('expected capability_disabled');
        } catch (ApiProblem $e) {
            $this->assertSame('capability_disabled', $e->problemCode);
        }
        $this->twoNode('cloud', heartbeatAge: null); // never seen
        $this->expectException(ApiProblem::class);
        $this->plan($r, 'ONLINE');
    }

    public function test_strategy_c_staleness_never_blocks_reception_or_other_resources(): void
    {
        $this->twoNode('cloud', heartbeatAge: 100000);
        $c = $this->res('C_DISABLE_ONLINE', 4, 1);
        $this->assertSame([1, 4], [$this->plan($c, 'STAFF')->unitLo, $this->plan($c, 'STAFF')->unitHi]);
        $a = $this->res('A_OFFLINE_ALLOCATION', 4, 1);
        $this->assertSame('CLOUD', $this->plan($a, 'ONLINE')->pool);
        $notOnline = $this->res('A_OFFLINE_ALLOCATION', 4, 1);
        $notOnline->update(['online_bookable' => false]);
        $this->expectException(ApiProblem::class);
        $this->plan($notOnline->fresh(), 'ONLINE');
    }

    public function test_confirming_on_the_local_node_emits_booking_confirmed_locally_for_reconciliation(): void
    {
        $this->twoNode('local', cloudReachable: false);
        $r = $this->res('A_OFFLINE_ALLOCATION', 5, 2);
        $held = $this->hold($r)->assertStatus(201)->json();
        $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '1000.0000']]], $this->idem($this->token, ['If-Match' => '"v'.$held['rowVersion'].'"']))->assertOk();
        $ev = DB::table('outbox_event')->where('event_type', 'BookingConfirmedLocally')->first();
        $this->assertNotNull($ev);
        $payload = json_decode($ev->payload, true);
        $this->assertSame('LOCAL', $payload['pool']);
        $this->assertSame([4], $payload['unitNos']);
        $this->assertSame($r->id, $payload['resourceId']);
    }
}
