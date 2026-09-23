<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingSyncApplier;
use App\Domain\Booking\Sync\BookingEventApplier;
use App\Domain\Sync\Support\InboundEvent;
use App\Domain\Ticketing\Models\Entitlement;
use App\Domain\Ticketing\Services\EntitlementSyncApplier;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingHelpers;
use Tests\TestCase;

/**
 * The other-node plumbing: what one node emits (outbox payloads) is enough for the other to recreate the booking + its QR entitlement,
 * validate a scan of it, and mirror redemptions exactly once. The Sync-module applier classes run only when that module exists.
 */
class SyncAppliersTest extends TestCase
{
    use BookingHelpers;

    private array $w;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        [, $this->token] = $this->staffWith($this->w, 'sync', array_unique([...self::BOOKING_PERMS, ...self::SCAN_PERMS]));
    }

    /** Confirmed booking + entitlement, then "move it to the other node": snapshots taken, every local row removed. @return array{0: array, 1: array, 2: string, 3: string} */
    private function shipped(): array
    {
        $r = $this->resource($this->w, ['mode' => 'INDIVIDUAL_CAPACITY', 'capacity' => 5, 'price' => '1000.0000']);
        $slot = $this->slot(10);
        $held = $this->postJson('/api/v1/bookings/hold', ['resourceId' => $r->id, 'start' => $slot[0], 'end' => $slot[1], 'quantity' => 2, 'customer' => ['name' => 'Chinedu']], $this->idem($this->token))->assertStatus(201)->json();
        $conf = $this->postJson("/api/v1/bookings/{$held['id']}/confirm", ['tenders' => [['tenderType' => 'CASH', 'amount' => '2000.0000']]], $this->idem($this->token, ['If-Match' => '"v'.$held['rowVersion'].'"']))->assertOk()->json();
        $booking = Booking::query()->with('resource')->findOrFail($conf['id']);
        $ent = Entitlement::query()->with('items')->findOrFail($conf['entitlementId']);
        $bookingSnap = app(BookingSyncApplier::class)->snapshot($booking);
        $entSnap = app(EntitlementSyncApplier::class)->snapshot($ent);
        $qr = $ent->qr_token;

        DB::table('slot_allocation')->delete();
        DB::table('booking_item')->delete();
        DB::table('booking')->delete();
        DB::table('entitlement_item')->delete();
        DB::table('entitlement')->delete();

        return [$bookingSnap, $entSnap, $qr, $r->id];
    }

    public function test_a_booking_and_its_qr_entitlement_can_be_recreated_on_the_other_node_and_scanned_there(): void
    {
        [$bookingSnap, $entSnap, $qr, $resourceId] = $this->shipped();
        $this->assertSame([1, 2], $bookingSnap['unitNos']);
        $this->assertSame('CONFIRMED', $bookingSnap['status']);

        DB::transaction(function () use ($bookingSnap, $entSnap) {
            app(BookingSyncApplier::class)->applySnapshot($bookingSnap);
            $this->assertTrue(app(EntitlementSyncApplier::class)->apply($entSnap));
        });
        $b = $this->getJson("/api/v1/bookings/{$bookingSnap['bookingId']}", $this->idem($this->token))->assertOk()->assertJsonPath('status', 'CONFIRMED')->assertJsonPath('number', $bookingSnap['number'])->assertJsonPath('quantity', 2)->json();
        $this->assertSame($bookingSnap['entitlementId'], $b['entitlementId']);
        $this->assertSame(2, DB::table('slot_allocation')->where('status', 'CONFIRMED')->count());
        $this->assertSame(['CLOUD'], DB::table('slot_allocation')->pluck('pool')->unique()->values()->all());

        // the mirrored slots really block: a third booking cannot take units 1-2, units 3-5 are still free
        $slot = $this->slot(10);
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => $resourceId, 'start' => $slot[0], 'end' => $slot[1], 'quantity' => 4], $this->idem($this->token))->assertStatus(409);
        $this->postJson('/api/v1/bookings/hold', ['resourceId' => $resourceId, 'start' => $slot[0], 'end' => $slot[1], 'quantity' => 3], $this->idem($this->token))->assertStatus(201);

        // the SAME QR token validates here: the item's window opens 15 min before the slot -> not yet, but it is recognised (not 404)
        $this->postJson("/api/v1/entitlement-tokens/{$qr}/redeem", ['action' => 'ENTRY', 'facilityId' => $this->w['arena']->id], $this->idem($this->token))->assertOk()->assertJsonPath('result', 'NOT_YET_VALID');
        // redelivery is a no-op
        $this->assertFalse(app(EntitlementSyncApplier::class)->apply($entSnap));
        $this->assertSame($bookingSnap['bookingId'], app(BookingSyncApplier::class)->applySnapshot($bookingSnap)->id);
        $this->assertSame(1, DB::table('entitlement')->count());
    }

    public function test_cancel_and_reschedule_from_the_other_node_release_and_move_the_local_allocation(): void
    {
        [$bookingSnap, $entSnap] = $this->shipped();
        DB::transaction(function () use ($bookingSnap, $entSnap) {
            app(BookingSyncApplier::class)->applySnapshot($bookingSnap);
            app(EntitlementSyncApplier::class)->apply($entSnap);
        });
        $to = CarbonImmutable::parse($this->slot(14)[0]);
        DB::transaction(fn () => app(BookingSyncApplier::class)->applyRescheduled($bookingSnap['bookingId'], $to, $to->addHour()));
        $this->assertSame([$to->format('Y-m-d H:i:s.u')], DB::table('slot_allocation')->pluck('slot_start')->unique()->values()->all());
        $this->assertSame(2, DB::table('slot_allocation')->count()); // both units moved

        DB::transaction(fn () => app(BookingSyncApplier::class)->applyCancelled($bookingSnap['bookingId'], 'customer cancelled online'));
        $this->assertSame(0, DB::table('slot_allocation')->count());
        $this->assertSame('CANCELLED', DB::table('booking')->value('status'));
        $this->assertSame('CANCELLED', DB::table('entitlement')->value('status'));
    }

    public function test_redemptions_made_on_the_other_node_are_mirrored_exactly_once(): void
    {
        [$bookingSnap, $entSnap] = $this->shipped();
        DB::transaction(function () use ($bookingSnap, $entSnap) {
            app(BookingSyncApplier::class)->applySnapshot($bookingSnap);
            app(EntitlementSyncApplier::class)->apply($entSnap);
        });
        $item = $entSnap['items'][0]['id'];
        $payload = ['entitlementItemId' => $item, 'entitlementId' => $entSnap['entitlementId'], 'action' => 'ENTRY', 'qty' => '1.000', 'redemptionId' => Ids::uuid7(),
            'facilityId' => $this->w['arena']->id, 'at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z')];
        $sync = app(EntitlementSyncApplier::class);
        $this->assertTrue($sync->mirrorRedemption('ENTRY', $payload));
        $this->assertFalse($sync->mirrorRedemption('ENTRY', $payload)); // same redemption id: nothing moves twice
        $this->assertEquals(1, DB::table('entitlement_item')->where('id', Ids::toBinary($item))->value('qty_redeemed'));
        $this->assertNull($sync->mirrorRedemption('ENTRY', ['entitlementItemId' => Ids::uuid7()] + $payload)); // unknown item -> defer
        $this->assertSame(1, DB::table('redemption')->count());
    }

    public function test_sync_module_appliers_report_conflicts_and_defer_out_of_order_events(): void
    {
        if (! class_exists('App\Domain\Sync\Support\ApplyResult')) {
            $this->markTestSkipped('Sync module is not installed on this branch.');
        }
        [$bookingSnap] = $this->shipped();
        $applier = app(BookingEventApplier::class);
        $event = fn (string $type, array $payload, int $v = 2) => new InboundEvent(Ids::uuid7(), $type, 'Booking', $payload['bookingId'], $v, $this->w['t']['org'], $this->w['t']['site'], null, 'cloud', now('UTC')->format('c'), $payload);

        $this->assertSame('DEFERRED', $applier->apply($event('OnlineBookingCancelled', ['bookingId' => $bookingSnap['bookingId'], 'reason' => 'x']))->kind); // booking not here yet
        $this->assertSame('APPLIED', $applier->apply($event('OnlineBookingCreated', $bookingSnap))->kind);
        $this->assertSame('APPLIED', $applier->apply($event('OnlineBookingCreated', $bookingSnap))->kind); // redelivery

        // a DIFFERENT booking claiming the same units is a BOOKING conflict, not silently resolved
        $other = ['bookingId' => Ids::uuid7(), 'number' => 'BK-X-0001', 'itemId' => Ids::uuid7()] + $bookingSnap;
        $result = $applier->apply($event('BookingConfirmedLocally', $other));
        $this->assertSame('CONFLICT', $result->kind);
        $this->assertSame('BOOKING', $result->category);
        $this->assertSame($bookingSnap['bookingId'], $result->localPayload['overlapping'][0]['id']);
        $this->assertSame('APPLIED', $applier->apply($event('OnlineBookingCancelled', ['bookingId' => $bookingSnap['bookingId'], 'reason' => 'x']))->kind);
        $this->assertSame(0, DB::table('slot_allocation')->count());
    }
}
